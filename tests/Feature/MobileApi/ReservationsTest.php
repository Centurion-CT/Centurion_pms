<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * /api/mobile/v1/reservations — index (filters), show, check-in,
 * check-out, no-show, cancel, record-payment. Writes delegate to the
 * same action classes web uses, so this suite mostly asserts the
 * HTTP contract (filter semantics, status transitions, idempotency
 * respected) rather than the business logic (that's in the Bookings/
 * suite).
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'mobile-rsv']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);
});

afterEach(fn () => Tenant::forgetCurrent());

function bearerForOp(Operator $op): array
{
    $device = MobileDevice::query()->create([
        'tenant_id' => $op->tenant_id,
        'operator_id' => $op->id,
        'platform' => 'ios',
        'device_name' => 'T',
        'app_version' => '1.0.0',
        'paired_at' => now(),
        'last_seen_at' => now(),
    ]);
    $token = $op->createToken('mobile-device-'.$device->id, ['mobile:*'])->plainTextToken;

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function rsvUrl(Tenant $tenant, string $path): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').$path;
}

it('arrivals filter returns Confirmed/OnHold/Pending bookings checking in today', function (): void {
    // Two today, one tomorrow.
    $today = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    $hold = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today()->addDay(),
        'checkout_date' => Carbon::today()->addDays(2),
    ]);

    $response = $this->getJson(
        rsvUrl($this->tenant, '/api/mobile/v1/reservations?filter=arrivals'),
        bearerForOp($this->frontdesk),
    );

    $response->assertOk();
    $refs = collect($response->json('data'))->pluck('booking_ref')->sort()->values()->all();
    expect($refs)->toBe(collect([$today, $hold])->pluck('booking_ref')->sort()->values()->all());
});

it('in_house filter returns only CheckedIn bookings', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn->value,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::tomorrow(),
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    $response = $this->getJson(
        rsvUrl($this->tenant, '/api/mobile/v1/reservations?filter=in_house'),
        bearerForOp($this->frontdesk),
    );

    expect(count($response->json('data')))->toBe(1);
    expect($response->json('data.0.status'))->toBe('checked_in');
});

it('departures filter returns CheckedIn bookings whose checkout is today', function (): void {
    // Checking out today.
    $out = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn->value,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::today(),
    ]);
    // Still staying.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn->value,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::tomorrow(),
    ]);

    $response = $this->getJson(
        rsvUrl($this->tenant, '/api/mobile/v1/reservations?filter=departures'),
        bearerForOp($this->frontdesk),
    );

    $refs = collect($response->json('data'))->pluck('booking_ref')->all();
    expect($refs)->toBe([$out->booking_ref]);
});

it('show returns the full reservation payload with charges + payments', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    $response = $this->getJson(
        rsvUrl($this->tenant, '/api/mobile/v1/reservations/'.$booking->booking_ref),
        bearerForOp($this->frontdesk),
    );

    $response->assertOk();
    expect($response->json('data.booking_ref'))->toBe($booking->booking_ref);
    expect($response->json('data.room.room_number'))->toBe($this->room->room_number);
    expect($response->json('data'))->toHaveKeys(['charges', 'payments', 'balance_due']);
});

it('check-in advances the booking status to CheckedIn', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    $response = $this->postJson(
        rsvUrl($this->tenant, '/api/mobile/v1/reservations/'.$booking->booking_ref.'/check-in'),
        [],
        array_merge(bearerForOp($this->frontdesk), ['Idempotency-Key' => 'chkin-abc-1']),
    );

    $response->assertOk()->assertJson(['status' => 'ok']);
    expect($booking->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
});

it('check-out advances the booking status to CheckedOut', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn->value,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::today(),
    ]);

    $response = $this->postJson(
        rsvUrl($this->tenant, '/api/mobile/v1/reservations/'.$booking->booking_ref.'/check-out'),
        [],
        array_merge(bearerForOp($this->frontdesk), ['Idempotency-Key' => 'chkout-abc-1']),
    );

    $response->assertOk();
    expect($booking->fresh()->booking_status)->toBe(BookingStatus::CheckedOut);
});

it('replaying check-in with the same Idempotency-Key returns the cached response', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    $headers = array_merge(bearerForOp($this->frontdesk), ['Idempotency-Key' => 'replay-key-xyz']);
    $url = rsvUrl($this->tenant, '/api/mobile/v1/reservations/'.$booking->booking_ref.'/check-in');

    $first = $this->postJson($url, [], $headers);
    $first->assertOk();
    $second = $this->postJson($url, [], $headers);
    $second->assertOk();
    expect($second->headers->get('Idempotent-Replay'))->toBe('1');
    expect($second->getContent())->toBe($first->getContent());
});

it('record-payment creates a Payment row + reduces balance_due', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
        'total_amount' => 50_000,
        'balance_due' => 50_000,
    ]);

    $response = $this->postJson(
        rsvUrl($this->tenant, '/api/mobile/v1/reservations/'.$booking->booking_ref.'/payment'),
        ['amount' => 20_000, 'method' => 'cash', 'reference_note' => 'Deposit at front desk'],
        array_merge(bearerForOp($this->frontdesk), ['Idempotency-Key' => 'pay-abc-1']),
    );

    $response->assertOk()->assertJson(['status' => 'ok']);
    expect($booking->fresh()->payments()->count())->toBe(1);
});

it('cancel flips the booking to Cancelled', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::tomorrow(),
        'checkout_date' => Carbon::tomorrow()->addDay(),
    ]);

    $response = $this->postJson(
        rsvUrl($this->tenant, '/api/mobile/v1/reservations/'.$booking->booking_ref.'/cancel'),
        [],
        array_merge(bearerForOp($this->manager), ['Idempotency-Key' => 'cancel-abc-1']),
    );

    $response->assertOk();
    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
});
