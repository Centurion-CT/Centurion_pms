<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Pos\Terminal;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'inhouse-picker']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small', 'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 20,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

// The cart / payment-sheet lives in Alpine.js now (resources/js/pos-cart.js)
// so we don't set method='room' via Livewire to trigger the picker. The
// booking picker itself is still Livewire — these tests exercise those
// endpoints directly.

function makeStay(Tenant $t, Property $p, BookingStatus $status, string $name, int $stayOffsetDaysStart = 0, int $stayLengthDays = 2): Booking
{
    $room = Room::factory()->for($t)->for($p)->create();
    /** @var Booking $booking */
    $booking = Booking::factory()->for($t)->for($room)->create([
        'guest_name' => $name,
        'booking_status' => $status,
        'checkin_date' => Carbon::today()->addDays($stayOffsetDaysStart),
        'checkout_date' => Carbon::today()->addDays($stayOffsetDaysStart + $stayLengthDays),
    ]);

    return $booking;
}

it('renders currently in-house guests in the room-tender picker', function (): void {
    $inHouse = makeStay($this->tenant, $this->property, BookingStatus::CheckedIn, 'Ada In-House', -1);
    makeStay($this->tenant, $this->property, BookingStatus::Confirmed, 'Ben Pre-Arrival', 5);
    makeStay($this->tenant, $this->property, BookingStatus::CheckedOut, 'Cee Checked-Out', -10, 5);

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->assertSee('Ada In-House')
        ->assertDontSee('Ben Pre-Arrival')
        ->assertDontSee('Cee Checked-Out')
        ->assertSeeHtml('data-testid="pay-inhouse-'.$inHouse->id.'"');
});

it('pickInHouseBooking sets bookingIdResolved and bookingRef in one tap', function (): void {
    $booking = makeStay($this->tenant, $this->property, BookingStatus::CheckedIn, 'Ada', -1);

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->call('pickInHouseBooking', $booking->id)
        ->assertSet('bookingIdResolved', $booking->id)
        ->assertSet('bookingRef', $booking->booking_ref)
        ->assertDispatched('pos-booking-resolved');
});

it('rejects picks for bookings that are not in-house', function (): void {
    $checkedOut = makeStay($this->tenant, $this->property, BookingStatus::CheckedOut, 'Cee', -5, 3);

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->call('pickInHouseBooking', $checkedOut->id)
        ->assertSet('bookingIdResolved', null)
        ->assertSet('bookingLookupError', 'That booking is no longer in-house.');
});

it('clearBookingSelection wipes both the id and the ref field', function (): void {
    $booking = makeStay($this->tenant, $this->property, BookingStatus::CheckedIn, 'Ada', 0);

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->call('pickInHouseBooking', $booking->id)
        ->call('clearBookingSelection')
        ->assertSet('bookingIdResolved', null)
        ->assertSet('bookingRef', '')
        ->assertDispatched('pos-booking-cleared');
});

it('still supports the manual booking-ref lookup fallback', function (): void {
    $walkIn = makeStay($this->tenant, $this->property, BookingStatus::Confirmed, 'Later Arrival', 3, 2);

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->set('bookingRef', $walkIn->booking_ref)
        ->call('lookupBooking')
        ->assertSet('bookingIdResolved', $walkIn->id);
});

it('renders the picker markup so the Alpine gate has something to show', function (): void {
    // The Alpine store gates visibility via `x-show`, but the picker DOM
    // is always rendered on the initial page load so Alpine has HTML to
    // reveal when the operator picks "Charge to room".
    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->assertSeeHtml('data-testid="pay-booking-picker"');
});

it('surfaces overstayed CheckedIn guests in the room-charge picker', function (): void {
    // Booked to check out yesterday, still CheckedIn today — the old
    // query with `checkout_date >= today` excluded them, breaking POS
    // charge-to-room for overstayed guests.
    $overstayer = makeStay($this->tenant, $this->property, BookingStatus::CheckedIn, 'Overstayer Ada', -3, 2);

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->assertSee('Overstayer Ada')
        ->assertSeeHtml('data-testid="pay-inhouse-'.$overstayer->id.'"');
});
