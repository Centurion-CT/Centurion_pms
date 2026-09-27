<?php

declare(strict_types=1);

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'wallet-cron']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 30_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->cat, 'category')->create();
    $this->customer = Customer::create([
        'name' => 'Overstay Guest',
        'email' => 'os@x.test',
        'lifecycle' => CustomerLifecycle::Active,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('adds a nightly overstay charge for a CheckedIn booking past checkout and sweeps from wallet', function (): void {
    Carbon::setTestNow('2026-06-10 07:45:00');

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2026-06-05',
        'checkout_date' => '2026-06-09',
        'customer_id' => $this->customer->id,
        'total_amount' => 90_000,
    ]);
    app(WalletService::class)->topup($this->customer, 30_000, 'seed');

    // Suppress current-tenant so the cron's makeCurrent() path runs.
    Tenant::forgetCurrent();

    $exit = Artisan::call('bookings:auto-deduct-from-wallet');
    expect($exit)->toBe(0);

    $this->tenant->makeCurrent();
    $charges = BookingCharge::query()
        ->where('booking_id', $booking->id)
        ->where('type', BookingChargeType::Overstay->value)
        ->get();

    expect($charges->count())->toBe(1);
    expect($charges->first()->settled)->toBeTrue();
    expect($charges->first()->settled_method)->toBe('wallet');
    expect($this->customer->fresh()->walletBalance())->toBe(0);

    Carbon::setTestNow();
});

it('leaves the overstay charge unsettled when the wallet is empty', function (): void {
    Carbon::setTestNow('2026-06-10 07:45:00');

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2026-06-05',
        'checkout_date' => '2026-06-09',
        'customer_id' => $this->customer->id,
        'total_amount' => 90_000,
    ]);

    Tenant::forgetCurrent();
    Artisan::call('bookings:auto-deduct-from-wallet');

    $this->tenant->makeCurrent();
    $charge = BookingCharge::query()->where('booking_id', $booking->id)->first();
    expect($charge)->not->toBeNull();
    expect($charge->settled)->toBeFalse();

    Carbon::setTestNow();
});

it('only creates one overstay charge per calendar day when re-run', function (): void {
    Carbon::setTestNow('2026-06-10 07:45:00');

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2026-06-05',
        'checkout_date' => '2026-06-09',
        'customer_id' => $this->customer->id,
        'total_amount' => 90_000,
    ]);

    Tenant::forgetCurrent();
    Artisan::call('bookings:auto-deduct-from-wallet');
    Artisan::call('bookings:auto-deduct-from-wallet');

    $this->tenant->makeCurrent();
    $count = BookingCharge::query()->where('booking_id', $booking->id)->count();
    expect($count)->toBe(1);

    Carbon::setTestNow();
});

it('catches up in one aggregate charge when the cron missed days', function (): void {
    // Regression: nightly cron used to bail out if any charge existed
    // for TODAY, so a missed day was lost forever and the guest was
    // permanently under-billed. The command now computes the delta
    // between nights owed and nights already on the ledger, so a run
    // after five silent days creates a single 5-night catch-up charge.
    Carbon::setTestNow('2026-06-15 07:45:00');

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2026-06-05',
        'checkout_date' => '2026-06-10', // Today is 06-15 → 5 nights over.
        'customer_id' => $this->customer->id,
        'total_amount' => 150_000,
    ]);
    app(WalletService::class)->topup($this->customer, 150_000, 'seed');

    Tenant::forgetCurrent();
    Artisan::call('bookings:auto-deduct-from-wallet');

    $this->tenant->makeCurrent();
    $charges = BookingCharge::query()
        ->where('booking_id', $booking->id)
        ->where('type', BookingChargeType::Overstay->value)
        ->get();

    expect($charges->count())->toBe(1);
    expect($charges->first()->amount)->toBe(150_000); // 5 nights × ₦30k
    expect($charges->first()->settled)->toBeTrue();
    expect($charges->first()->description)->toContain('5 nights overstay');
    expect($this->customer->fresh()->walletBalance())->toBe(0);

    Carbon::setTestNow();
});

it('tops up on the next run when the wallet only partially covered a prior catch-up', function (): void {
    // Two runs across two days with a wallet that can only cover one
    // night at a time: day 1 creates a 1-night charge and settles it;
    // day 2 sees the accrued second night uncharged, creates a
    // 1-night top-up charge and settles that too. No permanent loss.
    Carbon::setTestNow('2026-06-11 07:45:00');

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2026-06-05',
        'checkout_date' => '2026-06-10',
        'customer_id' => $this->customer->id,
        'total_amount' => 150_000,
    ]);
    app(WalletService::class)->topup($this->customer, 30_000, 'seed');

    Tenant::forgetCurrent();
    Artisan::call('bookings:auto-deduct-from-wallet');

    // Advance a day, top up again, re-run. Rebind the tenant first —
    // the cron's makeCurrent() reset when it finished, and WalletService
    // needs a bound tenant to find the existing wallet row.
    $this->tenant->makeCurrent();
    Carbon::setTestNow('2026-06-12 07:45:00');
    app(WalletService::class)->topup($this->customer->fresh(), 30_000, 'seed');
    Tenant::forgetCurrent();
    Artisan::call('bookings:auto-deduct-from-wallet');

    $this->tenant->makeCurrent();
    $charges = BookingCharge::query()
        ->where('booking_id', $booking->id)
        ->where('type', BookingChargeType::Overstay->value)
        ->orderBy('id')
        ->get();

    expect($charges->count())->toBe(2);
    expect($charges->sum('amount'))->toBe(60_000);
    expect($charges->every(fn ($c) => $c->settled === true))->toBeTrue();

    Carbon::setTestNow();
});

it('auto-collects the balance due for OnHold bookings past deadline', function (): void {
    Carbon::setTestNow('2026-06-10 07:45:00');

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'checkin_date' => '2026-06-15',
        'checkout_date' => '2026-06-18',
        'total_amount' => 100_000,
        'balance_due' => 70_000,
        'balance_due_at' => Carbon::parse('2026-06-08 12:00:00'),
        'customer_id' => $this->customer->id,
    ]);
    app(WalletService::class)->topup($this->customer, 100_000, 'seed');

    Tenant::forgetCurrent();
    Artisan::call('bookings:auto-deduct-from-wallet');

    $this->tenant->makeCurrent();
    $booking->refresh();
    expect($booking->booking_status)->toBe(BookingStatus::Confirmed);
    expect((int) $booking->balance_due)->toBe(0);
    expect($this->customer->fresh()->walletBalance())->toBe(30_000); // 100k - 70k

    $wallet_payment = Payment::query()
        ->where('booking_id', $booking->id)
        ->where('method', 'wallet')
        ->first();
    expect($wallet_payment)->not->toBeNull();
    expect((int) $wallet_payment->amount)->toBe(70_000);

    Carbon::setTestNow();
});
