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
 * /api/mobile/v1/rooms/available + /bookings — manual walk-in create.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'mobile-book']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe',
        'capacity_adults' => 2, 'capacity_children' => 1,
        'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->r101 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->cat->id,
        'room_number' => '101',
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    $this->r102 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->cat->id,
        'room_number' => '102',
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);

    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);
    $this->housekeeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
});

afterEach(fn () => Tenant::forgetCurrent());

function bearerForBookings(Operator $op): array
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

function mobileBookingsUrl(Tenant $tenant, string $path): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').$path;
}

it('availableRooms returns rooms that fit the party size + date range', function (): void {
    $checkin = Carbon::tomorrow()->toDateString();
    $checkout = Carbon::tomorrow()->addDays(2)->toDateString();

    $response = $this->getJson(
        mobileBookingsUrl($this->tenant,
            "/api/mobile/v1/rooms/available?checkin=$checkin&checkout=$checkout&adults=2&children=0"),
        bearerForBookings($this->frontdesk),
    );

    $response->assertOk();
    $numbers = collect($response->json('data'))->pluck('room_number')->sort()->values()->all();
    expect($numbers)->toBe(['101', '102']);
});

it('availableRooms filters out rooms whose category cannot fit the party', function (): void {
    $checkin = Carbon::tomorrow()->toDateString();
    $checkout = Carbon::tomorrow()->addDays(2)->toDateString();

    $response = $this->getJson(
        mobileBookingsUrl($this->tenant,
            "/api/mobile/v1/rooms/available?checkin=$checkin&checkout=$checkout&adults=5&children=0"),
        bearerForBookings($this->frontdesk),
    );

    $response->assertOk();
    expect(count($response->json('data')))->toBe(0);   // Deluxe only fits 2 adults.
});

it('availableRooms excludes rooms already booked in the range', function (): void {
    $checkin = Carbon::tomorrow();
    $checkout = Carbon::tomorrow()->addDays(2);
    // Block via the availability service (what the row would be if
    // CreateBooking had run) — a bare Booking row alone doesn't
    // change availability.
    app(\App\Services\Availability\AvailabilityService::class)
        ->blockRange($this->r101, $checkin, $checkout, 'test-booked');

    $response = $this->getJson(
        mobileBookingsUrl($this->tenant,
            '/api/mobile/v1/rooms/available'
            ."?checkin={$checkin->toDateString()}"
            ."&checkout={$checkout->toDateString()}"
            .'&adults=1&children=0'),
        bearerForBookings($this->frontdesk),
    );

    $response->assertOk();
    $numbers = collect($response->json('data'))->pluck('room_number')->sort()->values()->all();
    expect($numbers)->toBe(['102']);   // 101 is booked.
});

it('availableRooms refuses roles that cannot create bookings', function (): void {
    $checkin = Carbon::tomorrow()->toDateString();
    $checkout = Carbon::tomorrow()->addDays(2)->toDateString();

    $response = $this->getJson(
        mobileBookingsUrl($this->tenant,
            "/api/mobile/v1/rooms/available?checkin=$checkin&checkout=$checkout&adults=1&children=0"),
        bearerForBookings($this->housekeeper),
    );

    $response->assertStatus(403)->assertJson(['error' => 'forbidden']);
});

it('create persists a walk-in booking + returns the fresh reservation', function (): void {
    $response = $this->postJson(
        mobileBookingsUrl($this->tenant, '/api/mobile/v1/bookings'),
        [
            'room_id' => $this->r101->id,
            'guest_name' => 'Ifeanyi Amadi',
            'guest_email' => 'ifeanyi@example.com',
            'guest_phone' => '+2347040000001',
            'adults' => 2,
            'children' => 0,
            'checkin' => Carbon::tomorrow()->toDateString(),
            'checkout' => Carbon::tomorrow()->addDay()->toDateString(),
            'payment_type' => 'full',
            'source' => 'walk_in',
        ],
        array_merge(bearerForBookings($this->frontdesk), ['Idempotency-Key' => 'walkin-abc-1']),
    );

    $response->assertOk()->assertJson(['status' => 'ok']);
    expect($response->json('reservation.guest_name'))->toBe('Ifeanyi Amadi');
    expect(Booking::query()->count())->toBe(1);
});

it('create with settle_now records a payment against the fresh booking', function (): void {
    $response = $this->postJson(
        mobileBookingsUrl($this->tenant, '/api/mobile/v1/bookings'),
        [
            'room_id' => $this->r101->id,
            'guest_name' => 'Ada Nwosu',
            'guest_email' => 'ada@example.com',
            'guest_phone' => '+2347040000002',
            'adults' => 1,
            'children' => 0,
            'checkin' => Carbon::tomorrow()->toDateString(),
            'checkout' => Carbon::tomorrow()->addDay()->toDateString(),
            'payment_type' => 'full',
            'source' => 'walk_in',
            'settle_now' => [
                'amount' => 30_000,
                'method' => 'cash',
                'reference_note' => 'Front desk',
            ],
        ],
        array_merge(bearerForBookings($this->frontdesk), ['Idempotency-Key' => 'settle-abc-1']),
    );

    $response->assertOk();
    $booking = Booking::query()->firstOrFail();
    expect($booking->payments()->count())->toBe(1);
});

it('create refuses housekeepers', function (): void {
    $response = $this->postJson(
        mobileBookingsUrl($this->tenant, '/api/mobile/v1/bookings'),
        [
            'room_id' => $this->r101->id,
            'guest_name' => 'X',
            'guest_email' => 'x@x.com',
            'guest_phone' => '+2347040000003',
            'adults' => 1,
            'children' => 0,
            'checkin' => Carbon::tomorrow()->toDateString(),
            'checkout' => Carbon::tomorrow()->addDay()->toDateString(),
            'payment_type' => 'full',
        ],
        array_merge(bearerForBookings($this->housekeeper), ['Idempotency-Key' => 'hk-refuse-1']),
    );

    $response->assertStatus(403)->assertJson(['error' => 'forbidden']);
    expect(Booking::query()->count())->toBe(0);
});
