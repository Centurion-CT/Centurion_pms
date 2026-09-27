<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cache-endpoint']);
    $this->tenant->makeCurrent();
    $this->propertyA = Property::factory()->for($this->tenant)->create();
    $this->propertyB = Property::factory()->for($this->tenant)->create();
    $this->roomA = Room::factory()->for($this->tenant)->for($this->propertyA)->create();
    $this->roomB = Room::factory()->for($this->tenant)->for($this->propertyB)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'current_property_id' => $this->propertyA->id,
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 25, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function callCache(array $params = []): \Illuminate\Testing\TestResponse
{
    $url = 'http://cache-endpoint.keverna.test/api/dashboard/search-cache';
    $qs = http_build_query($params);
    return test()->getJson($qs ? $url.'?'.$qs : $url);
}

it('rejects unauthenticated requests', function (): void {
    // JSON request → 401 from the auth middleware (rather than a
    // 302 to the login form).
    callCache()->assertStatus(401);
});

it('returns rows for the operator\'s current property scope', function (): void {
    $mine = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Amaka Onwudiwe',
        'checkin_date' => Carbon::today(),
    ]);
    // Same tenant, different property — must NOT appear.
    Booking::factory()->for($this->tenant)->for($this->roomB)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Chinedu Ike',
    ]);

    $this->actingAs($this->manager, 'web');
    $res = callCache()
        ->assertOk()
        ->assertJsonStructure(['rows', 'deleted_ids', 'server_time', 'has_more']);

    $ids = collect($res->json('rows'))->pluck('id')->all();
    expect($ids)->toBe([$mine->id]);
});

it('shapes each row into the pre-lowered / normalised search payload', function (): void {
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::Confirmed,
        'guest_name' => 'Amaka Onwudiwe',
        'guest_email' => 'AMAKA@example.test',
        'guest_phone' => '+234 809-123 4567',
        'checkin_date' => Carbon::today()->addDays(2),
    ]);

    $this->actingAs($this->manager, 'web');
    $row = callCache()->assertOk()->json('rows.0');

    expect($row['guest_name'])->toBe('Amaka Onwudiwe');
    expect($row['guest_name_lc'])->toBe('amaka onwudiwe');
    expect($row['guest_email_lc'])->toBe('amaka@example.test');
    expect($row['guest_phone_normalised'])->toBe('2348091234567');
    expect($row['property_id'])->toBe($this->propertyA->id);
    expect($row['tenant_id'])->toBe($this->tenant->id);
});

it('includes bookings inside the +30d / -3d check-in window even if not live', function (): void {
    // Pending is not in LIVE_STATUSES, but the check-in date is
    // inside the rolling window → should still land in the cache
    // so front desk can search a not-yet-confirmed reservation.
    $b = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::Pending,
        'guest_name' => 'Window Guest',
        'checkin_date' => Carbon::today()->addDays(10),
    ]);

    $this->actingAs($this->manager, 'web');
    $ids = collect(callCache()->assertOk()->json('rows'))->pluck('id')->all();
    expect($ids)->toContain($b->id);
});

it('excludes bookings whose checkin is well past the window and not live', function (): void {
    $old = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'guest_name' => 'Ancient Guest',
        'checkin_date' => Carbon::today()->subMonths(3),
        'checkout_date' => Carbon::today()->subMonths(3)->addDays(2),
    ]);

    $this->actingAs($this->manager, 'web');
    $ids = collect(callCache()->assertOk()->json('rows'))->pluck('id')->all();
    expect($ids)->not->toContain($old->id);
});

it('returns only rows changed since ?since on incremental pulls', function (): void {
    $before = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today(),
    ]);

    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    $sinceIso = Carbon::now()->subMinutes(2)->toIso8601String();

    Carbon::setTestNow(Carbon::now()->addMinutes(3));
    $after = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today(),
    ]);

    $this->actingAs($this->manager, 'web');
    $ids = collect(callCache(['since' => $sinceIso])->assertOk()->json('rows'))->pluck('id')->all();

    expect($ids)->toContain($after->id);
    expect($ids)->not->toContain($before->id);
});

it('surfaces deleted_ids for bookings that fell out of the cache', function (): void {
    $sinceIso = Carbon::now()->subMinutes(5)->toIso8601String();

    // Was live yesterday, cancelled since — should appear in deleted_ids.
    $cancelled = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::Cancelled,
        'checkin_date' => Carbon::today()->addDays(2),
    ]);

    $this->actingAs($this->manager, 'web');
    $res = callCache(['since' => $sinceIso])->assertOk();

    expect($res->json('deleted_ids'))->toContain($cancelled->id);
});

it('deleted_ids is always empty on the cold hydration (no since)', function (): void {
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::Cancelled,
        'checkin_date' => Carbon::today()->addDays(2),
    ]);

    $this->actingAs($this->manager, 'web');
    expect(callCache()->assertOk()->json('deleted_ids'))->toBe([]);
});

it('has_more is true when the page fills all 500 slots', function (): void {
    // Small property with a huge chunk of bookings; test just the shape.
    // With 500+ synthetic rows, has_more must flip true.
    Booking::factory()
        ->count(501)
        ->for($this->tenant)
        ->for($this->roomA)
        ->create([
            'booking_status' => BookingStatus::CheckedIn,
            'checkin_date' => Carbon::today(),
        ]);

    $this->actingAs($this->manager, 'web');
    expect(callCache()->assertOk()->json('has_more'))->toBeTrue();
});

it('honours an explicit ?property_id override for the manager', function (): void {
    $bA = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::CheckedIn,
    ]);
    $bB = Booking::factory()->for($this->tenant)->for($this->roomB)->create([
        'booking_status' => BookingStatus::CheckedIn,
    ]);

    $this->actingAs($this->manager, 'web');
    $ids = collect(callCache(['property_id' => $this->propertyB->id])->assertOk()->json('rows'))
        ->pluck('id')->all();

    expect($ids)->toContain($bB->id);
    expect($ids)->not->toContain($bA->id);
});
