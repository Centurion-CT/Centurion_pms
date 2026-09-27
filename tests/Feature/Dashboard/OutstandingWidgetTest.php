<?php

declare(strict_types=1);

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Home;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Dashboard "Outstanding balances" widget — amount was reading the
 * raw balance_due column, which is ₦0 for guests whose deposit was
 * fully paid but who've since racked up unsettled POS charges. The
 * widget should reflect outstandingTotal() so the operator sees the
 * real exposure. Row click also has to open the reservation detail,
 * not the records-audit view.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'outstanding-widget']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the outstanding total (balance_due + unsettled charges), not raw balance_due', function (): void {
    // Deposit paid in full (balance_due = 0), but ₦155k in unsettled
    // POS charges. Prior bug: widget rendered "₦0" and hid the
    // exposure.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Hassana Ameh',
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 50_000,
        'balance_due' => 0,
        'balance_due_at' => null,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'type' => BookingChargeType::FoodBeverage->value,
        'description' => 'Rooftop bar',
        'amount' => 155_000,
        'settled' => false,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSee('Hassana Ameh')
        ->assertSee('₦155,000')
        ->assertSee('1 unsettled charge(s)');
});

it('renders the balance_due amount when the outstanding is the on-hold remainder', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'guest_name' => 'Deposit Only',
        'total_amount' => 100_000,
        'deposit_amount' => 30_000,
        'balance_due' => 70_000,
        'balance_due_at' => Carbon::tomorrow(),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSee('Deposit Only')
        ->assertSee('₦70,000');
});

it('outstanding row links to the reservation detail, not the records view', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'guest_name' => 'Row Click Guest',
        'total_amount' => 100_000,
        'balance_due' => 70_000,
        'balance_due_at' => Carbon::tomorrow(),
    ]);

    $html = Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->html();

    // Extract the outstanding widget block via its testid, then assert
    // the row inside points at the reservation route. Recent-bookings
    // and other widgets link to /dashboard/bookings/... — we only care
    // about the outstanding one here.
    preg_match(
        '/data-testid="widget-outstanding".*?<\/section>/s',
        $html,
        $matches,
    );
    expect($matches)->not->toBeEmpty();
    expect($matches[0])->toContain('/dashboard/reservations/'.$booking->booking_ref);
    expect($matches[0])->not->toContain('/dashboard/bookings/'.$booking->booking_ref);
});
