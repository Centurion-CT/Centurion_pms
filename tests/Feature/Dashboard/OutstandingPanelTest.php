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
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Outstanding-balances panel: clicking the Outstanding pulse tile
 * opens a modal listing every open book with Settle-all + inline
 * part-payment. Ledger writes match the Reservations Show flow via
 * the shared RecordPartPayment action so payments hit /payments.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'outstanding-panel']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('the Outstanding pulse tile toggles the panel + renders every open book', function (): void {
    $b1 = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Ada Owed',
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000, 'balance_due' => 15_000,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertSet('outstandingOpen', false)
        ->call('openOutstandingPanel')
        ->assertSet('outstandingOpen', true)
        ->assertSee('data-testid="outstanding-panel"', false)
        ->assertSee('Ada Owed')
        ->assertSee('data-testid="outstanding-panel-row-'.$b1->id.'"', false)
        ->call('closeOutstandingPanel')
        ->assertSet('outstandingOpen', false);
});

it('settleOutstandingAll writes a Payment row + clears the outstanding', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Full Settle',
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000, 'balance_due' => 0,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id,
        'type' => BookingChargeType::FoodBeverage->value,
        'description' => 'Room service',
        'amount' => 22_000, 'settled' => false,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->call('openOutstandingPanel')
        ->set('outstandingSettleMethod', 'cash')
        ->call('settleOutstandingAll', $b->id);

    // Ledger row lands on the general payments table.
    $payment = Payment::query()->where('booking_id', $b->id)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect((int) $payment->amount)->toBe(22_000);
    expect($payment->method)->toBe('cash');

    // Outstanding is now clear.
    expect($b->refresh()->outstandingTotal())->toBe(0);
});

it('submitOutstandingPartPay collects a partial + leaves the remainder', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Partial Ada',
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000, 'balance_due' => 25_000,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->call('openOutstandingPanel')
        ->call('openOutstandingPartPay', $b->id)
        ->set('outstandingPartPayAmount', 10_000)
        ->set('outstandingPartPayMethod', 'transfer')
        ->call('submitOutstandingPartPay')
        ->assertDispatched('notify');

    expect((int) $b->refresh()->balance_due)->toBe(15_000);
    $payment = Payment::query()->where('booking_id', $b->id)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect((int) $payment->amount)->toBe(10_000);
    expect($payment->method)->toBe('transfer');
});

it('refuses part payment above the outstanding', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Over Ada',
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000, 'balance_due' => 10_000,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->call('openOutstandingPanel')
        ->call('openOutstandingPartPay', $b->id)
        ->set('outstandingPartPayAmount', 999_999)
        ->set('outstandingPartPayMethod', 'cash')
        ->call('submitOutstandingPartPay')
        ->assertDispatched('notify');

    expect(Payment::query()->where('booking_id', $b->id)->count())->toBe(0);
    expect((int) $b->refresh()->balance_due)->toBe(10_000);
});
