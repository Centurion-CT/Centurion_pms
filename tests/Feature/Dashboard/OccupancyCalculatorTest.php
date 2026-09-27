<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Dashboard\OccupancyCalculator;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->svc = app(OccupancyCalculator::class);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('returns 0% when there are no active rooms', function (): void {
    $result = $this->svc->rolling();

    expect($result['percent'])->toBe(0);
    expect($result['total_nights'])->toBe(0);
    expect($result['booked_nights'])->toBe(0);
});

it('returns 0% when rooms exist but no bookings in window', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->create();
    Room::factory()->for($this->tenant)->for($this->property)->create();

    $result = $this->svc->rolling();

    expect($result['percent'])->toBe(0);
    expect($result['total_nights'])->toBe(60);  // 2 rooms × 30 days
});

it('computes occupancy correctly with partial bookings', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 12:00:00'));

    $room = Room::factory()->for($this->tenant)->for($this->property)->create();

    // 5 booked nights inside the 30-day window (8/28 .. 9/01 fits 8/01..8/30).
    foreach (['2030-08-26', '2030-08-27', '2030-08-28', '2030-08-29', '2030-08-30'] as $d) {
        AvailabilityBlock::factory()->for($this->tenant)->for($room)->booked()
            ->create(['date' => $d]);
    }

    $result = $this->svc->rolling();

    expect($result['booked_nights'])->toBe(5);
    expect($result['total_nights'])->toBe(30);  // 1 room × 30 days
    expect($result['percent'])->toBe(17);       // round(5/30 × 100)
});

it('excludes Blocked (operator-hold) rows from the numerator', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 12:00:00'));

    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    AvailabilityBlock::factory()->for($this->tenant)->for($room)->booked()
        ->create(['date' => '2030-08-28']);
    AvailabilityBlock::factory()->for($this->tenant)->for($room)
        ->create(['date' => '2030-08-29', 'type' => AvailabilityBlockType::Blocked]);

    $result = $this->svc->rolling();

    expect($result['booked_nights'])->toBe(1);  // only the Booked row counts
});

it('excludes inactive rooms from the denominator', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 12:00:00'));

    Room::factory()->for($this->tenant)->for($this->property)->create();
    Room::factory()->for($this->tenant)->for($this->property)->create([
        'status' => RoomStatus::Inactive,
    ]);

    $result = $this->svc->rolling();

    expect($result['total_nights'])->toBe(30);  // only the active room counts
});

it('honours a custom window length', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 12:00:00'));

    Room::factory()->for($this->tenant)->for($this->property)->create();

    $result = $this->svc->rolling(7);

    expect($result['total_nights'])->toBe(7);
    expect($result['window_from'])->toBe('2030-08-24');
    expect($result['window_to'])->toBe('2030-08-30');
});

it('is scoped to the current tenant — other tenants invisible', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 12:00:00'));

    $myRoom = Room::factory()->for($this->tenant)->for($this->property)->create();
    AvailabilityBlock::factory()->for($this->tenant)->for($myRoom)->booked()
        ->create(['date' => '2030-08-29']);

    Tenant::forgetCurrent();
    $otherTenant = Tenant::factory()->create();
    $otherTenant->makeCurrent();
    $otherProp = Property::factory()->for($otherTenant)->create();
    $otherRoom = Room::factory()->for($otherTenant)->for($otherProp)->create();
    AvailabilityBlock::factory()->for($otherTenant)->for($otherRoom)->booked()
        ->createMany([
            ['date' => '2030-08-28'], ['date' => '2030-08-29'], ['date' => '2030-08-30'],
        ]);

    // Run from tenant B's context — should ONLY see B's bookings + B's rooms.
    $resultB = $this->svc->rolling();
    expect($resultB['booked_nights'])->toBe(3);
    expect($resultB['total_nights'])->toBe(30);

    // Swap back to tenant A and verify A is unaffected.
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();
    $resultA = $this->svc->rolling();
    expect($resultA['booked_nights'])->toBe(1);
    expect($resultA['total_nights'])->toBe(30);
});
