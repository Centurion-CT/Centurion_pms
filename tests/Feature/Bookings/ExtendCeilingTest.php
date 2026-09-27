<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * A guest's extend should honour the next reservation on the room -
 * the datepicker caps at the next checkin, and any attempt to push
 * past it (via the wallet path OR the pay-by-card path) is refused
 * with a clean message rather than the raw availability exception.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'ext-ceiling']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Std', 'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();

    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    // Current guest - checked in, checks out tomorrow.
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Amara Okafor',
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'nights' => 2, 'total_amount' => 80_000, 'room_total' => 80_000, 'balance_due' => 0,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('maxExtendableCheckoutDate returns the next reservation checkin', function (): void {
    // Next guest checks in 3 days from today - current guest can
    // extend up to that day but not past it.
    $nextCheckin = Carbon::today()->addDays(3);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $nextCheckin,
        'checkout_date' => $nextCheckin->copy()->addDays(2),
    ]);

    $ceiling = $this->booking->maxExtendableCheckoutDate();
    expect($ceiling)->not->toBeNull();
    expect($ceiling->toDateString())->toBe($nextCheckin->toDateString());
});

it('maxExtendableCheckoutDate returns null when no next reservation exists', function (): void {
    expect($this->booking->maxExtendableCheckoutDate())->toBeNull();
});

it('maxExtendableCheckoutDate ignores cancelled reservations', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Cancelled,
        'checkin_date' => Carbon::today()->addDays(2),
        'checkout_date' => Carbon::today()->addDays(4),
    ]);
    expect($this->booking->maxExtendableCheckoutDate())->toBeNull();
});

it('guest portal blocks an extend past the ceiling with a clean message', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDays(3),
        'checkout_date' => Carbon::today()->addDays(5),
    ]);
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'extend')
        // Trying to extend to 5 days out, but next booking checks in
        // at day 3 - should be refused.
        ->set('extendCheckoutDate', Carbon::today()->addDays(5)->toDateString())
        ->call('submitExtend')
        ->assertSet('authError', fn (string $e) => str_contains($e, 'reserved by another guest'));

    // Booking untouched.
    expect($this->booking->fresh()->checkout_date->toDateString())
        ->toBe(Carbon::today()->addDay()->toDateString());
});

it('guest portal lets an extend through when it stops exactly at the ceiling', function (): void {
    $nextCheckin = Carbon::today()->addDays(3);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $nextCheckin,
        'checkout_date' => $nextCheckin->copy()->addDays(2),
    ]);
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'extend')
        ->set('extendCheckoutDate', $nextCheckin->toDateString())
        ->call('submitExtend')
        ->assertSet('step', 'home');

    expect($this->booking->fresh()->checkout_date->toDateString())->toBe($nextCheckin->toDateString());
});
