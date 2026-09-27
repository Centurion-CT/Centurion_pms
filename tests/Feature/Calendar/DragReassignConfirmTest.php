<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Calendar\PropertyView;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'drag-reassign']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->deluxeCat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe',
        'base_rate' => 40_000,
        'capacity_adults' => 2,
        'capacity_children' => 0,
        'min_nights' => 1,
    ]);
    $this->suiteCat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite',
        'base_rate' => 60_000,
        'capacity_adults' => 2,
        'capacity_children' => 0,
        'min_nights' => 1,
    ]);
    $this->deluxeA = Room::factory()->for($this->tenant)->for($this->property)->for($this->deluxeCat, 'category')->create([
        'room_number' => '101',
        'status' => RoomStatus::Active->value,
    ]);
    $this->deluxeB = Room::factory()->for($this->tenant)->for($this->property)->for($this->deluxeCat, 'category')->create([
        'room_number' => '102',
        'status' => RoomStatus::Active->value,
    ]);
    $this->suite = Room::factory()->for($this->tenant)->for($this->property)->for($this->suiteCat, 'category')->create([
        'room_number' => '201',
        'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

function calBooking(Tenant $t, Room $r, array $overrides = []): Booking
{
    return Booking::factory()->for($t)->for($r)->confirmed()->create([
        'guest_name' => $overrides['guest_name'] ?? 'Ada Obi',
        'guest_phone' => '+2348011112222',
        'checkin_date' => $overrides['checkin'] ?? Carbon::tomorrow()->toDateString(),
        'checkout_date' => $overrides['checkout'] ?? Carbon::tomorrow()->addDays(2)->toDateString(),
        'nights' => $overrides['nights'] ?? 2,
        'total_amount' => $overrides['total'] ?? 80_000,
        'room_total' => $overrides['total'] ?? 80_000,
        'balance_due' => 0,
        'room_category_id' => $r->room_category_id,
    ]);
}

it('populates the confirm modal with same-rate copy when swap is within a category', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeB->id)
        ->assertSet('showReassignConfirm', true)
        ->assertSet('reassignBookingId', $booking->id)
        ->assertSet('reassignFromRoomLabel', '101')
        ->assertSet('reassignToRoomLabel', '102')
        ->assertSet('reassignOldRate', 40_000)
        ->assertSet('reassignNewRate', 40_000)
        ->assertSet('reassignRateDelta', 0)
        ->assertSet('reassignRemainingNights', 2)
        ->assertSet('reassignCrossCategory', false)
        ->assertSet('reassignBlocker', '');
});

it('previews a positive rate delta on cross-category upgrade', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->suite->id)
        ->assertSet('showReassignConfirm', true)
        ->assertSet('reassignOldRate', 40_000)
        ->assertSet('reassignNewRate', 60_000)
        ->assertSet('reassignRateDelta', 40_000)     // (60k - 40k) × 2 nights
        ->assertSet('reassignRemainingNights', 2)
        ->assertSet('reassignCrossCategory', true);
});

it('flags target-taken as a blocker when the target room has a conflicting booking', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);
    // Seed a Booked block on the target room that overlaps our booking's
    // dates so AvailabilityService::isAvailable() returns false.
    $conflict = calBooking($this->tenant, $this->deluxeB, [
        'guest_name' => 'Sam',
        'checkin' => Carbon::tomorrow()->toDateString(),
        'checkout' => Carbon::tomorrow()->addDays(2)->toDateString(),
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->deluxeB)
        ->booked()
        ->create([
            'date' => Carbon::tomorrow()->toDateString(),
            'booking_id' => $conflict->id,
        ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->deluxeB)
        ->booked()
        ->create([
            'date' => Carbon::tomorrow()->addDay()->toDateString(),
            'booking_id' => $conflict->id,
        ]);

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeB->id)
        ->assertSet('showReassignConfirm', true)
        ->assertSet('reassignBlocker', 'Target room already has another booking in that range.');
});

it('flags inactive rooms as a blocker', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);
    $this->deluxeB->update(['status' => RoomStatus::Inactive->value]);

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeB->id)
        ->assertSet('showReassignConfirm', true)
        ->assertSet('reassignBlocker', 'Target room is not active.');
});

it('rejects the swap when the booking is Cancelled', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);
    $booking->forceFill(['booking_status' => BookingStatus::Cancelled->value])->save();

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeB->id)
        ->assertSet('showReassignConfirm', false)
        ->assertDispatched('notify');
});

it('rejects a swap to the same room', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeA->id)
        ->assertSet('showReassignConfirm', false)
        ->assertDispatched('notify');
});

it('confirms the drag and moves the booking to the target room', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeB->id)
        ->assertSet('showReassignConfirm', true)
        ->call('confirmReassignFromDrag')
        ->assertSet('showReassignConfirm', false)
        ->assertDispatched('notify');

    expect($booking->fresh()->room_id)->toBe($this->deluxeB->id);
});

it('blocks the swap when the guest is CheckedIn and past checkout', function (): void {
    // Guest checked in on the 14th, was due to check out on the 15th,
    // today is the 16th → 0 remaining nights + effective checkin > checkout.
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 10, 0));

    $booking = calBooking($this->tenant, $this->deluxeA, [
        'checkin' => '2026-09-14',
        'checkout' => '2026-09-15',
        'nights' => 1,
    ]);
    $booking->forceFill(['booking_status' => BookingStatus::CheckedIn->value])->save();

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeB->id)
        ->assertSet('showReassignConfirm', true)
        ->assertSet('reassignRemainingNights', 0)
        ->assertSet('reassignBlocker', 'Guest is past checkout with no remaining nights. Extend the stay or check them out before swapping rooms.')
        // Real range shows left-to-right, not the inverted effective range.
        ->assertSet('reassignCheckinLabel', 'Mon, 14 Sep')
        ->assertSet('reassignCheckoutLabel', 'Tue, 15 Sep');

    Carbon::setTestNow();
});

it('refuses to commit while a blocker is set', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);
    $this->deluxeB->update(['status' => RoomStatus::Inactive->value]);

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeB->id)
        ->assertSet('reassignBlocker', 'Target room is not active.')
        ->call('confirmReassignFromDrag')
        ->assertDispatched('notify');

    // Booking untouched; the confirm never reached ReassignRoom.
    expect($booking->fresh()->room_id)->toBe($this->deluxeA->id);
});

it('cancel closes the modal and clears the pending swap state', function (): void {
    $booking = calBooking($this->tenant, $this->deluxeA);

    Livewire::test(PropertyView::class)
        ->call('openReassignConfirm', $booking->id, $this->deluxeB->id)
        ->assertSet('showReassignConfirm', true)
        ->call('cancelReassignConfirm')
        ->assertSet('showReassignConfirm', false)
        ->assertSet('reassignBookingId', null)
        ->assertSet('reassignToRoomId', null);

    expect($booking->fresh()->room_id)->toBe($this->deluxeA->id); // untouched
});
