<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Bookings\Show;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Booking Show — Mark checked in / out buttons must be hidden for
 * bookings whose check-in date is still in the future. Marking one
 * as CheckedIn before the guest is due would create phantom
 * in-house nights and break occupancy math.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'checkin-guard']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('hides the check-in button for a future Confirmed booking', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today()->addDays(4),
        'checkout_date'  => Carbon::today()->addDays(5),
    ]);

    Livewire::test(Show::class, ['bookingRef' => $booking->booking_ref])
        ->assertViewHas('canCheckIn', false)
        ->assertViewHas('canCheckOut', false);
});

it('shows the check-in button on the day-of arrival for Confirmed bookings', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today(),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);

    Livewire::test(Show::class, ['bookingRef' => $booking->booking_ref])
        ->assertViewHas('canCheckIn', true)
        ->assertViewHas('canCheckOut', false);   // not CheckedIn yet
});

it('shows check-out ONLY when status is CheckedIn (not Confirmed / OnHold)', function (): void {
    $b1 = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today()->subDay(),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);
    Livewire::test(Show::class, ['bookingRef' => $b1->booking_ref])
        ->assertViewHas('canCheckOut', false);

    $b2 = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date'   => Carbon::today()->subDay(),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);
    Livewire::test(Show::class, ['bookingRef' => $b2->booking_ref])
        ->assertViewHas('canCheckOut', true);
});

it('a late-arrival (check-in date in the past) does NOT surface check-in — operator must Extend or mark NoShow', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today()->subDays(2),   // guest is 2 days late
        'checkout_date'  => Carbon::today()->addDay(),
    ]);

    // Backdated check-in would silently retro-date the arrival and hide
    // the fact that the guest never really arrived on their booked
    // night. Operator must re-slot the booking (Extend to today) or
    // mark it NoShow — an explicit call, not a click of "Check in".
    Livewire::test(Show::class, ['bookingRef' => $booking->booking_ref])
        ->assertViewHas('canCheckIn', false);
});

it('canCancel is false once the guest is CheckedIn — exit is Check out, not Cancel', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date'   => Carbon::today()->subDay(),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);

    Livewire::test(Show::class, ['bookingRef' => $booking->booking_ref])
        ->assertViewHas('canCancel', false);
});

it('canConfirm is true only for Pending / OnHold — a CheckedIn walk-in does NOT show Confirm', function (): void {
    // Pending → yes
    $pending = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'checkin_date'   => Carbon::today()->addDay(),
        'checkout_date'  => Carbon::today()->addDays(2),
    ]);
    Livewire::test(Show::class, ['bookingRef' => $pending->booking_ref])
        ->assertViewHas('canConfirm', true);

    // OnHold → yes
    $onHold = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold,
        'checkin_date'   => Carbon::today()->addDays(3),
        'checkout_date'  => Carbon::today()->addDays(4),
    ]);
    Livewire::test(Show::class, ['bookingRef' => $onHold->booking_ref])
        ->assertViewHas('canConfirm', true);

    // CheckedIn → NO (the bug this test locks in — a walk-in already
    // checked in should not still see "Confirm booking").
    $checkedIn = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date'   => Carbon::today(),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);
    Livewire::test(Show::class, ['bookingRef' => $checkedIn->booking_ref])
        ->assertViewHas('canConfirm', false);

    // NoShow → NO (terminal)
    $noShow = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::NoShow,
        'checkin_date'   => Carbon::today()->subDay(),
        'checkout_date'  => Carbon::today(),
    ]);
    Livewire::test(Show::class, ['bookingRef' => $noShow->booking_ref])
        ->assertViewHas('canConfirm', false);
});
