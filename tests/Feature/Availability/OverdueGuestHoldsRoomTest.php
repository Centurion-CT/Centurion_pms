<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Availability\AvailabilityService;
use App\Services\Bookings\RoomAllocator;
use Illuminate\Support\Carbon;

/**
 * An overdue guest (checked in, booked checkout passed) has no blocks
 * left but is still in the room: it must not be offered from today.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'overdue-hold']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->overdue = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(4),
        'checkout_date' => Carbon::today()->subDay(),
    ]);
    $this->svc = app(AvailabilityService::class);
});

afterEach(fn () => Tenant::forgetCurrent());

it('treats the room as taken for a stay that includes tonight', function (): void {
    expect($this->svc->isAvailable($this->room, Carbon::today(), Carbon::today()->addDays(2)))->toBeFalse();
    expect(app(RoomAllocator::class)->availableRoomsInProperty($this->property->id, Carbon::today(), Carbon::today()->addDay())->pluck('id'))
        ->not->toContain($this->room->id);
});

it('still offers the room from tomorrow on', function (): void {
    expect($this->svc->isAvailable($this->room, Carbon::today()->addDay(), Carbon::today()->addDays(3)))->toBeTrue();
});

it('lets the overdue booking itself extend into its own room', function (): void {
    expect($this->svc->isAvailable($this->room, Carbon::today()->subDay(), Carbon::today()->addDay(), $this->overdue->id))->toBeTrue();
});

it('frees the room once the guest checks out', function (): void {
    $this->overdue->update(['booking_status' => BookingStatus::CheckedOut]);
    expect($this->svc->isAvailable($this->room, Carbon::today(), Carbon::today()->addDay()))->toBeTrue();
});
