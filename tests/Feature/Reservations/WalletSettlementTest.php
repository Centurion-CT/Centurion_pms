<?php

declare(strict_types=1);

use App\Actions\Bookings\AddBookingCharge;
use App\Actions\Bookings\SettleChargesFromWallet;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Wallet as a settlement method on the reservation detail page —
 * "Wallet" is available in the settlement dropdown ONLY when the
 * linked guest has funded wallet balance, and settling from it
 * routes through SettleChargesFromWallet (spend + mark charge with
 * settled_method='wallet').
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'wallet-settle']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->customer = Customer::create([
        'name' => 'Chi Guest',
        'email' => 'chi@x.test',
        'lifecycle' => CustomerLifecycle::Active,
    ]);

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ─── Action layer ─────────────────────────────────────────────── */

it('handleOne pays one specific charge from the guest wallet + marks it settled', function (): void {
    app(WalletService::class)->topup(
        customer: $this->customer, amount: 20_000, description: 'top up',
    );
    $charge = app(AddBookingCharge::class)->handle(
        $this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 8_000,
    );

    $ok = app(SettleChargesFromWallet::class)->handleOne($charge, $this->manager);

    expect($ok)->toBeTrue();
    expect($charge->fresh()->settled)->toBeTrue();
    expect($charge->fresh()->settled_method)->toBe('wallet');
    expect($this->customer->fresh()->walletBalance())->toBe(12_000);
});

it('handleOne refuses when wallet balance is short of the charge', function (): void {
    app(WalletService::class)->topup(
        customer: $this->customer, amount: 1_000, description: 'top up',
    );
    $charge = app(AddBookingCharge::class)->handle(
        $this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 8_000,
    );

    $ok = app(SettleChargesFromWallet::class)->handleOne($charge, $this->manager);

    expect($ok)->toBeFalse();
    expect($charge->fresh()->settled)->toBeFalse();
    expect($this->customer->fresh()->walletBalance())->toBe(1_000); // nothing spent
});

/* ─── Livewire wiring ──────────────────────────────────────────── */

it('reservation show surfaces the Wallet option ONLY when the guest wallet is funded', function (): void {
    // Empty wallet: option hidden.
    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertViewHas('walletBalance', 0);

    // Add a charge so the settlement panel renders, then top up + refresh.
    app(AddBookingCharge::class)->handle(
        $this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 5_000,
    );
    app(WalletService::class)->topup(
        customer: $this->customer, amount: 15_000, description: 'top up',
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertViewHas('walletBalance', 15_000)
        ->assertSee('data-testid="settle-method-wallet"', false);
});

it('settleAll(wallet) sweeps charges from the wallet + records status message', function (): void {
    app(WalletService::class)->topup(
        customer: $this->customer, amount: 30_000, description: 'top up',
    );
    app(AddBookingCharge::class)->handle(
        $this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 8_000,
    );
    app(AddBookingCharge::class)->handle(
        $this->booking, $this->manager, BookingChargeType::RoomService, 'drinks', 4_000,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('settleMethod', 'wallet')
        ->call('settleAll')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok'
            && ($p['message'] ?? null) === 'Settled 2 charge(s) from wallet.');

    expect($this->customer->fresh()->walletBalance())->toBe(18_000);
});

it('settleAll(wallet) surfaces an error message when the wallet is empty', function (): void {
    app(AddBookingCharge::class)->handle(
        $this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 8_000,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('settleMethod', 'wallet')
        ->call('settleAll')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err'
            && ($p['message'] ?? null) === 'Wallet is empty or nothing outstanding could be covered, pick another method.');
});

it('settleOne(wallet) settles the picked charge from the wallet + leaves others alone', function (): void {
    app(WalletService::class)->topup(
        customer: $this->customer, amount: 20_000, description: 'top up',
    );
    $c1 = app(AddBookingCharge::class)->handle(
        $this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 8_000,
    );
    $c2 = app(AddBookingCharge::class)->handle(
        $this->booking, $this->manager, BookingChargeType::RoomService, 'drinks', 4_000,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('settleMethod', 'wallet')
        ->call('settleOne', $c1->id)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok'
            && ($p['message'] ?? null) === 'Charge paid from wallet.');

    expect($c1->fresh()->settled)->toBeTrue();
    expect($c1->fresh()->settled_method)->toBe('wallet');
    expect($c2->fresh()->settled)->toBeFalse();          // untouched
    expect($this->customer->fresh()->walletBalance())->toBe(12_000);
});
