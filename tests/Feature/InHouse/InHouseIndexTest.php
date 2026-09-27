<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\InHouse\Index as InHouseIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'inhouse-page']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->operator, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

function inHousePageBooking(Tenant $t, Room $r, int $checkinOffset, int $checkoutOffset, string $name = 'Guest'): Booking
{
    return Booking::factory()->for($t)->for($r)->create([
        'guest_name'     => $name,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date'   => Carbon::today()->addDays($checkinOffset),
        'checkout_date'  => Carbon::today()->addDays($checkoutOffset),
    ]);
}

it('lists only CheckedIn guests', function (): void {
    inHousePageBooking($this->tenant, $this->room, -1, 2, 'In House Guy');
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name'     => 'Not Checked In',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today()->addDay(),
        'checkout_date'  => Carbon::today()->addDays(3),
    ]);

    Livewire::test(InHouseIndex::class)
        ->assertSee('In House Guy')
        ->assertDontSee('Not Checked In');
});

it('orders by checkout date ascending - soonest departures first', function (): void {
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create();
    inHousePageBooking($this->tenant, $this->room, -3, 5, 'Later Departure');
    inHousePageBooking($this->tenant, $roomB, -3, 1, 'Earlier Departure');

    $rendered = Livewire::test(InHouseIndex::class)->html();
    expect(strpos($rendered, 'Earlier Departure'))->toBeLessThan(strpos($rendered, 'Later Departure'));
});

it('makes each in-house row open the detail panel on click', function (): void {
    $b = inHousePageBooking($this->tenant, $this->room, -1, 2, 'Row Click');

    // Row now dispatches `in-house-panel-open` with the booking id.
    // The detail drawer is client-side + pre-hydrated, so no wire trip.
    Livewire::test(InHouseIndex::class)
        ->assertSee('data-testid="in-house-row-'.$b->booking_ref.'"', false)
        ->assertSee('role="button"', false)
        ->assertSee("in-house-panel-open', { id: {$b->id} }", false)
        // Panel factory is emitted on the page.
        ->assertSee('window.inHousePagePanel', false);
});

it('overdue guests contribute their projected overstay to totalAccrued', function (): void {
    // Regression: buildInHouseSheetData eager-loaded room WITHOUT
    // category.base_rate. $room->base_rate is an accessor that
    // reads $room->category->base_rate; a partially-loaded category
    // returned null so unchargedOverstayAmount() zeroed and the
    // "Total accrued balance" read ₦0 in the sheet header even
    // when the per-row Currently-in-house list clearly showed
    // money owed for the same guests.
    $category = \App\Models\RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2,
    ]);
    $room = \App\Models\Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $category->id,
    ]);

    // Guest checked in 3 days ago, booked to leave yesterday → 1 night overstay.
    Booking::factory()->for($this->tenant)->for($room)->create([
        'guest_name' => 'Overdue Guy',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(3),
        'checkout_date' => Carbon::today()->subDay(),
        'nights' => 2,
        'room_total' => 80_000,
        'total_amount' => 80_000,
        'balance_due' => 0,
    ]);

    Livewire::test(InHouseIndex::class)
        ->assertViewHas('totalAccruedNaira', 40_000);
});

it('shows the committed per-night rate - base tier when breakfast not opted in', function (): void {
    // Daily rate now reflects the tier the guest committed to at
    // booking time (base or breakfast), NOT room_total ÷ nights.
    // That older calc drifted after extends / fees. See the blade
    // comment in in-house/index.blade.php.
    $category = \App\Models\RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000,
        'breakfast_included_rate' => 45_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $room = \App\Models\Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $category->id,
    ]);
    Booking::factory()->for($this->tenant)->for($room)->create([
        'guest_name'     => 'Base Rate Guest',
        'booking_status' => BookingStatus::CheckedIn,
        'breakfast_included' => false,
        'checkin_date'   => Carbon::today()->subDay(),
        'checkout_date'  => Carbon::today()->addDays(2),
        'nights'         => 3,
        // Deliberately mis-shaped room_total to prove the display
        // ignores it - the old averaging bug would show 30,000.
        'room_total'     => 90_000,
    ]);

    Livewire::test(InHouseIndex::class)
        ->assertSeeHtml('data-testid="in-house-daily-rate"')
        ->assertSeeHtml('40,000')
        ->assertDontSee('30,000');
});

it('shows the breakfast tier when the guest opted in', function (): void {
    $category = \App\Models\RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000,
        'breakfast_included_rate' => 45_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $room = \App\Models\Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $category->id,
    ]);
    Booking::factory()->for($this->tenant)->for($room)->create([
        'guest_name'     => 'Breakfast Guest',
        'booking_status' => BookingStatus::CheckedIn,
        'breakfast_included' => true,
        'checkin_date'   => Carbon::today()->subDay(),
        'checkout_date'  => Carbon::today()->addDay(),
        'nights'         => 2,
        'room_total'     => 90_000,
    ]);

    Livewire::test(InHouseIndex::class)
        ->assertSeeHtml('data-testid="in-house-daily-rate"')
        ->assertSeeHtml('45,000');
});

it('surfaces the KPI counts including overdue', function (): void {
    // In-house, checkout today.
    inHousePageBooking($this->tenant, $this->room, -2, 0, 'Ends Today');
    // In-house, overdue (checkout was yesterday).
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create();
    inHousePageBooking($this->tenant, $roomB, -5, -1, 'Overstayed');
    // In-house, checkout tomorrow.
    $roomC = Room::factory()->for($this->tenant)->for($this->property)->create();
    inHousePageBooking($this->tenant, $roomC, -1, 1, 'Ends Tomorrow');

    Livewire::test(InHouseIndex::class)
        ->assertViewHas('counts', function ($counts) {
            return $counts['in_house'] === 3
                && $counts['checkouts_today'] === 1
                && $counts['checkouts_tomorrow'] === 1
                && $counts['overdue'] === 1;
        });
});
