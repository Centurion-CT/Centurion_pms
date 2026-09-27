<?php

declare(strict_types=1);

use App\Actions\Bookings\AddBookingCharge;
use App\Actions\Bookings\SettleChargesWalletFirst;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\Booking;
use App\Models\BookingCharge;
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
 * "Settle everything" with a non-wallet method now drains the wallet
 * first (splitting a charge if the balance falls between rows) and
 * collects the shortfall in the picked method. This gives the operator
 * one action instead of two — matches the mental model of "use the
 * wallet, guest pays the difference at the counter."
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'wallet-first-settle']);
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

it('drains the wallet in full and settles the rest in the fallback method', function (): void {
    // Wallet has ₦50k. Charges are ₦5k + ₦150k = ₦155k.
    // Expected: ₦5k charge fully wallet, ₦150k split into ₦45k wallet + ₦105k cash.
    app(WalletService::class)->topup($this->customer, 50_000, description: 'top up');
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::FoodBeverage, 'POS · Restaurant', 5_000);
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::FoodBeverage, 'POS · Rooftop Bar', 150_000);

    $result = app(SettleChargesWalletFirst::class)->handle($this->booking->fresh(), $this->manager, 'cash');

    expect($result['wallet'])->toBe(50_000);
    expect($result['wallet_cleared'])->toBe(2); // ₦5k row + split-wallet piece of the ₦150k row
    expect($result['fallback_cleared'])->toBe(1); // just the split-cash piece

    expect($this->customer->fresh()->walletBalance())->toBe(0);

    $charges = BookingCharge::query()->where('booking_id', $this->booking->id)->orderBy('id')->get();
    expect($charges->every(fn ($c) => $c->settled))->toBeTrue();

    $walletTotal   = (int) $charges->where('settled_method', 'wallet')->sum('amount');
    $cashTotal     = (int) $charges->where('settled_method', 'cash')->sum('amount');
    expect($walletTotal)->toBe(50_000);
    expect($cashTotal)->toBe(105_000);
    expect($walletTotal + $cashTotal)->toBe(155_000);
});

it('is a no-op on the wallet side when the guest has no wallet balance', function (): void {
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 8_000);

    $result = app(SettleChargesWalletFirst::class)->handle($this->booking->fresh(), $this->manager, 'cash');

    expect($result['wallet'])->toBe(0);
    expect($result['wallet_cleared'])->toBe(0);
    expect($result['fallback_cleared'])->toBe(1);
    expect(BookingCharge::query()->where('booking_id', $this->booking->id)->where('settled', true)->count())->toBe(1);
});

it('uses only the wallet when it fully covers everything', function (): void {
    app(WalletService::class)->topup($this->customer, 100_000, description: 'top up');
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 5_000);
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::RoomService, 'drinks', 20_000);

    $result = app(SettleChargesWalletFirst::class)->handle($this->booking->fresh(), $this->manager, 'cash');

    expect($result['wallet'])->toBe(25_000);
    expect($result['wallet_cleared'])->toBe(2);
    expect($result['fallback_cleared'])->toBe(0);
    expect($this->customer->fresh()->walletBalance())->toBe(75_000);
});

/* ─── Livewire wiring ──────────────────────────────────────────── */

it('settleAll(cash) drains the wallet first + collects the rest as cash in one click', function (): void {
    app(WalletService::class)->topup($this->customer, 50_000, description: 'top up');
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::FoodBeverage, 'POS · Restaurant', 5_000);
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::FoodBeverage, 'POS · Rooftop Bar', 150_000);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('settleMethod', 'cash')
        ->call('settleAll')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok'
            && str_contains((string) ($p['message'] ?? ''), '₦50,000 from wallet')
            && str_contains((string) ($p['message'] ?? ''), 'via cash'));

    expect($this->customer->fresh()->walletBalance())->toBe(0);
    $charges = BookingCharge::query()->where('booking_id', $this->booking->id)->get();
    expect($charges->every(fn ($c) => $c->settled))->toBeTrue();
    expect((int) $charges->where('settled_method', 'wallet')->sum('amount'))->toBe(50_000);
    expect((int) $charges->where('settled_method', 'cash')->sum('amount'))->toBe(105_000);
});

it('settleAll keeps the wallet-only path intact for method=wallet', function (): void {
    // Wallet-only never touches cash — even if the wallet can't cover
    // everything, it just stops. Regression guard for the pre-existing
    // path so operators who explicitly want "wallet only" still get it.
    app(WalletService::class)->topup($this->customer, 10_000, description: 'top up');
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::FoodBeverage, 'dinner', 5_000);
    app(AddBookingCharge::class)->handle($this->booking, $this->manager, BookingChargeType::FoodBeverage, 'lunch', 20_000);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('settleMethod', 'wallet')
        ->call('settleAll');

    // Only the ₦5k charge was covered — the ₦20k row stays unsettled
    // and NO cash rows were created (contrast with the wallet-first path).
    $unsettled = BookingCharge::query()->where('booking_id', $this->booking->id)->where('settled', false)->get();
    expect($unsettled)->toHaveCount(1);
    expect((int) $unsettled->first()->amount)->toBe(20_000);
    expect(BookingCharge::query()->where('booking_id', $this->booking->id)
        ->where('settled_method', 'cash')->count())->toBe(0);
});
