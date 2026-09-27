<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Client-side detail panel on the Reservations index — rows dispatch
 * `arrival-panel-open` with a booking id, and a shared Alpine
 * component hydrated with pre-loaded payloads opens the panel
 * instantly (no server round-trip just to view). These tests verify:
 *   - Rows are clickable + wired to the dispatch
 *   - The panel factory + payload for each visible booking is emitted
 *   - Panel actions (no-show, cancel) route back to Livewire methods
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'arrival-panel']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'room_number' => '206',
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($this->operator, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('emits arrival-panel-open on row click + hydrates the Alpine payload with the booking data', function (): void {
    $today = Carbon::today();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Digieneni',
        'guest_phone' => '08039419136',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $today->toDateString(),
        'checkout_date' => $today->copy()->addDay()->toDateString(),
        'total_amount' => 50_000,
        'balance_due' => 0,
    ]);

    $response = Livewire::test(ReservationsIndex::class);
    $html = $response->html();

    // Row dispatches the open event with the booking id
    expect($html)->toContain('$dispatch(\'arrival-panel-open\', { id: '.$booking->id.' })');

    // Shared factory is emitted once + payload contains this booking
    expect($html)->toContain('window.arrivalGuestPanel');
    expect($html)->toContain('data-testid="arrivals-panel"');
    expect($html)->toContain('Digieneni');
    expect($html)->toContain('08039419136');
});

it('does not emit a panel payload when there are no arrivals', function (): void {
    // No arrivals today or tomorrow — the panel component still
    // renders (dispatch listeners bind at parse time) but its panels
    // array is empty.
    $html = Livewire::test(ReservationsIndex::class)->html();
    expect($html)->toContain('window.arrivalGuestPanel');
    // Empty JS array literal, no booking objects
    expect($html)->toContain('panels: []');
});

it('markNoShow can be called from the panel using the booking ref', function (): void {
    $today = Carbon::today();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Skip Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $today->toDateString(),
        'checkout_date' => $today->copy()->addDay()->toDateString(),
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('markNoShow', $booking->booking_ref);

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::NoShow);
});

it('cancelReservation can be called from the panel using the booking ref', function (): void {
    $today = Carbon::today();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Bail Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $today->toDateString(),
        'checkout_date' => $today->copy()->addDay()->toDateString(),
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('cancelReservation', $booking->booking_ref);

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
});

it('checkInFromPanel can be called from the panel + flips the booking to CheckedIn', function (): void {
    $today = Carbon::today();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Arriving Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $today->toDateString(),
        'checkout_date' => $today->copy()->addDay()->toDateString(),
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('checkInFromPanel', $booking->booking_ref)
        ->assertDispatched('notify');

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
});

it('payload flags can_check_in true for a Confirmed booking arriving today', function (): void {
    $today = Carbon::today();
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Eligible Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $today->toDateString(),
        'checkout_date' => $today->copy()->addDay()->toDateString(),
    ]);

    $html = Livewire::test(ReservationsIndex::class)->html();

    expect($html)->toContain('data-testid="arrivals-panel-check-in"');
    // Illuminate\Support\Js::from unicode-escapes double quotes as ".
    expect($html)->toContain('\u0022can_check_in\u0022:true');
});

it('payload flags can_check_in false for arriving-tomorrow bookings', function (): void {
    $today = Carbon::today();
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Tomorrow Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $today->copy()->addDay()->toDateString(),
        'checkout_date' => $today->copy()->addDays(2)->toDateString(),
    ]);

    $html = Livewire::test(ReservationsIndex::class)->html();

    expect($html)->toContain('\u0022can_check_in\u0022:false');
});

/* ── In-house sheet panel ── */

it('emits inhouse-sheet-panel-open on sheet row click + hydrates the panel payload', function (): void {
    $today = Carbon::today();
    $inHouse = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Glory Igiemwin',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => $today->copy()->subDays(2)->toDateString(),
        'checkout_date' => $today->copy()->addDay()->toDateString(),
        'total_amount' => 80_000,
    ]);

    $response = Livewire::test(ReservationsIndex::class);
    $html = $response->html();

    // Sheet row is a button that dispatches the open event
    expect($html)->toContain('$dispatch(\'inhouse-sheet-panel-open\', { id: '.$inHouse->id.' })');

    // Panel factory + payload rendered once on the page
    expect($html)->toContain('window.inHouseSheetPanel');
    expect($html)->toContain('data-testid="inhouse-sheet-panel"');
    expect($html)->toContain('Glory Igiemwin');
});

it('renders the in-house sheet panel factory even when nothing is checked in', function (): void {
    // Panel component still parses so its event listener binds; the
    // panels array is just empty.
    $html = Livewire::test(ReservationsIndex::class)->html();
    expect($html)->toContain('window.inHouseSheetPanel');
});
