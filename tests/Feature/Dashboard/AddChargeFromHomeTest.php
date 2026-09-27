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
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Header "Add charge" flow on the operator home page. Lets the front
 * desk drop an ad-hoc charge (room service, food, damage, ...) onto
 * an in-house guest without leaving the dashboard, with an inline
 * "Waive" toggle for cases the operator forgives on the spot.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'dash-add-charge']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '203']);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    // In-house guest ready to receive a charge.
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Ada In-house',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'nights' => 2,
        'total_amount' => 80_000, 'room_total' => 80_000,
        'balance_due' => 0,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('renders the Add charge trigger + panel on the header', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSee('data-testid="dash-add-charge-trigger"', false)
        ->assertSee('Add charge');
});

it('lists in-house guests in the picker when panel opens', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('openAddChargePanel')
        ->assertSee('data-testid="dash-add-charge-guest-'.$this->booking->booking_ref.'"', false)
        ->assertSee('Ada In-house');
});

it('filters the picker by room number', function (): void {
    // Second in-house guest for filter check.
    $roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '303']);
    $b2 = Booking::factory()->for($this->tenant)->for($roomB)->create([
        'guest_name' => 'Peter Room303',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('openAddChargePanel')
        ->set('addChargeSearch', '303')
        ->assertSee('data-testid="dash-add-charge-guest-'.$b2->booking_ref.'"', false)
        ->assertDontSee('data-testid="dash-add-charge-guest-'.$this->booking->booking_ref.'"', false);
});

it('creates a BookingCharge for the picked guest on submit', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('openAddChargePanel')
        ->set('addChargeBookingId', $this->booking->id)
        ->set('addChargeType', 'food_beverage')
        ->set('addChargeAmount', 8_500)
        ->set('addChargeDescription', '2x dinner')
        ->call('submitAddCharge')
        ->assertDispatched('notify');

    $charge = BookingCharge::query()->where('booking_id', $this->booking->id)->latest('id')->first();
    expect($charge)->not->toBeNull();
    expect($charge->type)->toEqual(BookingChargeType::FoodBeverage);
    expect((int) $charge->amount)->toBe(8_500);
    expect($charge->description)->toBe('2x dinner');
    expect((bool) $charge->settled)->toBeFalse();
});

it('the Waive toggle marks the new charge as settled with settled_method=waived', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('openAddChargePanel')
        ->set('addChargeBookingId', $this->booking->id)
        ->set('addChargeType', 'damage')
        ->set('addChargeAmount', 15_000)
        ->set('addChargeDescription', 'Broken lamp')
        ->set('addChargeWaive', true)
        ->call('submitAddCharge')
        ->assertDispatched('notify');

    $charge = BookingCharge::query()->where('booking_id', $this->booking->id)->latest('id')->first();
    expect($charge->type)->toEqual(BookingChargeType::Damage);
    expect((bool) $charge->settled)->toBeTrue();
    expect($charge->settled_method)->toBe('waived');
});

it('refuses to submit without a picked guest', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('openAddChargePanel')
        ->set('addChargeAmount', 5_000)
        ->call('submitAddCharge')
        ->assertDispatched('notify');

    expect(BookingCharge::query()->count())->toBe(0);
});

it('refuses to submit against a not-in-house booking (Confirmed)', function (): void {
    $confirmed = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDay(),
        'checkout_date' => Carbon::today()->addDays(3),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('openAddChargePanel')
        ->set('addChargeBookingId', $confirmed->id)
        ->set('addChargeType', 'other')
        ->set('addChargeAmount', 3_000)
        ->call('submitAddCharge')
        ->assertDispatched('notify');

    expect(BookingCharge::query()->where('booking_id', $confirmed->id)->count())->toBe(0);
});
