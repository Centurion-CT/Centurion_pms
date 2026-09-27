<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'offline-sync']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 4,
        'capacity_children' => 2,
        'base_rate' => 50_000,
        'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    // Seed a live subscription so EnforceSubscriptionAccess doesn't
    // flip the tenant into read-only and reject the offline-sync write.
    $this->actingAs($this->operator, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

function offlineSyncUrl(Tenant $tenant): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain')
        .'/dashboard/reservations/offline-sync';
}

function samplePayload(Room $room, string $key): array
{
    return [
        'offline_key' => $key,
        'room_id' => $room->id,
        'guest_name' => 'Ada Offline',
        'guest_phone' => '+2348011112222',
        'adults' => 2,
        'children' => 0,
        'checkin_date' => Carbon::today()->addDay()->toDateString(),
        'checkout_date' => Carbon::today()->addDays(3)->toDateString(),
        'payment_status' => 'confirmed',
        'breakfast_included' => false,
        'manual_discount_type' => 'percent',
        'manual_discount_percent' => 0,
        'manual_discount_fixed_input' => 0,
    ];
}

it('creates a booking from a queued offline payload', function (): void {
    $key = (string) Str::uuid();

    $response = $this->postJson(offlineSyncUrl($this->tenant), samplePayload($this->room, $key));

    $response->assertOk()->assertJson([
        'status' => 'created',
        'offline_key' => $key,
    ]);

    $booking = Booking::query()->where('offline_key', $key)->first();
    expect($booking)->not->toBeNull();
    expect($booking->guest_name)->toBe('Ada Offline');
    expect($booking->source)->toBe(BookingSource::WalkIn);
    // Booking lands as Pending — offline flow deliberately doesn't
    // record the payment inline (network gap could mean the wallet
    // balance or Paystack settlement changed since the operator
    // captured "paid" locally). Front desk clears it via the normal
    // reservation-detail settle path once it syncs.
    expect($booking->booking_status)->toBe(BookingStatus::Pending);
});

it('short-circuits duplicate posts with the same offline_key', function (): void {
    $key = (string) Str::uuid();
    $payload = samplePayload($this->room, $key);

    $this->postJson(offlineSyncUrl($this->tenant), $payload)->assertOk();
    $before = Booking::query()->count();

    $second = $this->postJson(offlineSyncUrl($this->tenant), $payload);

    $second->assertOk()->assertJson([
        'status' => 'duplicate',
        'offline_key' => $key,
    ]);
    expect(Booking::query()->count())->toBe($before);
});

it('returns a conflict when the room is already booked for those dates', function (): void {
    // Someone else grabbed the same room+dates while the operator
    // was offline. Sync worker gets a 409 back and surfaces the
    // conflict to the front desk instead of double-booking.
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => Carbon::today()->addDay()->toDateString()]);

    $response = $this->postJson(
        offlineSyncUrl($this->tenant),
        samplePayload($this->room, (string) Str::uuid()),
    );

    $response->assertStatus(409)->assertJson(['status' => 'conflict']);
});

it('rejects malformed payloads with 422', function (): void {
    $response = $this->postJson(offlineSyncUrl($this->tenant), [
        'offline_key' => 'not-a-uuid',
    ]);

    $response->assertStatus(422)->assertJson(['status' => 'error']);
});
