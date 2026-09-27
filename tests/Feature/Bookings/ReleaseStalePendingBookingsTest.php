<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('cancels a Pending booking older than 15 minutes and deletes its Booked nights', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->createMany([
            ['date' => '2030-09-01', 'booking_id' => $b->id],
            ['date' => '2030-09-02', 'booking_id' => $b->id],
        ]);

    Carbon::setTestNow(Carbon::parse('2030-08-01 10:20:00')); // 20 min later

    $this->artisan('bookings:release-stale-pending')->assertSuccessful();

    expect($b->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
    expect(AvailabilityBlock::where('booking_id', $b->id)->count())->toBe(0);
});

it('leaves a Pending booking under 15 minutes old alone', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
    ]);

    Carbon::setTestNow(Carbon::parse('2030-08-01 10:10:00')); // 10 min later
    $this->artisan('bookings:release-stale-pending');

    expect($b->fresh()->booking_status)->toBe(BookingStatus::Pending);
});

it('does NOT touch Confirmed bookings even if old', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    Carbon::setTestNow(Carbon::parse('2030-08-01 11:00:00'));
    $this->artisan('bookings:release-stale-pending');

    expect($b->fresh()->booking_status)->toBe(BookingStatus::Confirmed);
});

it('does NOT touch OnHold bookings even if old', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create();

    Carbon::setTestNow(Carbon::parse('2030-08-01 11:00:00'));
    $this->artisan('bookings:release-stale-pending');

    expect($b->fresh()->booking_status)->toBe(BookingStatus::OnHold);
});

it('does NOT delete Blocked rows that happen to share a date (only Booked)', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create([
            'date' => '2030-09-01',
            'type' => AvailabilityBlockType::Blocked, // operator manual hold — keep
        ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2030-09-02', 'booking_id' => $b->id]);

    Carbon::setTestNow(Carbon::parse('2030-08-01 10:20:00'));
    $this->artisan('bookings:release-stale-pending');

    expect(AvailabilityBlock::count())->toBe(1);
    expect(AvailabilityBlock::first()->type)->toBe(AvailabilityBlockType::Blocked);
});

it('is idempotent — second run does nothing', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
    ]);

    Carbon::setTestNow(Carbon::parse('2030-08-01 10:20:00'));
    $this->artisan('bookings:release-stale-pending');
    $this->artisan('bookings:release-stale-pending')->assertSuccessful();

    expect(Booking::where('booking_status', BookingStatus::Cancelled->value)->count())->toBe(1);
});

it('processes stale Pending bookings across multiple tenants in one run', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));

    $b1 = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
    ]);

    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create();
    $tenantB->makeCurrent();
    $propB = Property::factory()->for($tenantB)->create();
    $roomB = Room::factory()->for($tenantB)->for($propB)->create();
    $b2 = Booking::factory()->for($tenantB)->for($roomB)->create([
        'booking_status' => BookingStatus::Pending,
    ]);
    Tenant::forgetCurrent();

    Carbon::setTestNow(Carbon::parse('2030-08-01 10:20:00'));
    $this->artisan('bookings:release-stale-pending');

    expect($b1->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
    expect($b2->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
});
