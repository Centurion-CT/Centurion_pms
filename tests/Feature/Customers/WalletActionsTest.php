<?php

declare(strict_types=1);

use App\Actions\Bookings\PayBookingFromWallet;
use App\Actions\Bookings\RecordManualPayment;
use App\Actions\Customers\RecordWalletTopup;
use App\Actions\Customers\RefundFromWallet;
use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'wallet-actions']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 40_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->cat, 'category')->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'name' => 'Manager M',
        'role' => OperatorRole::Manager->value,
    ]);
    $this->customer = Customer::create([
        'name' => 'Chi Guest',
        'email' => 'chi@x.test',
        'lifecycle' => CustomerLifecycle::Active,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('RecordWalletTopup credits the wallet and logs an activity', function (): void {
    app(RecordWalletTopup::class)->handle(
        customer: $this->customer,
        operator: $this->operator,
        amount: 25_000,
        method: 'cash',
        referenceNote: 'Front desk drop',
    );

    expect($this->customer->fresh()->walletBalance())->toBe(25_000);

    $activity = Activity::query()
        ->where('event', 'wallet_topped_up')
        ->first();
    expect($activity)->not->toBeNull();
    expect($activity->description)->toContain('Chi Guest');
});

it('RefundFromWallet requires the operator to be a manager', function (): void {
    $svc = app(WalletService::class);
    $svc->topup($this->customer, 30_000, 'seed');

    $posStaff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff->value,
    ]);

    expect(fn () => app(RefundFromWallet::class)->handle(
        customer: $this->customer,
        operator: $posStaff,
        amount: 5_000,
        method: 'cash',
        reason: 'Guest asked',
    ))->toThrow(AuthorizationException::class);
});

it('RefundFromWallet debits balance and captures the reason', function (): void {
    $svc = app(WalletService::class);
    $svc->topup($this->customer, 30_000, 'seed');

    $tx = app(RefundFromWallet::class)->handle(
        customer: $this->customer,
        operator: $this->operator,
        amount: 8_000,
        method: 'cash',
        reason: 'Change on checkout day',
    );

    expect($this->customer->fresh()->walletBalance())->toBe(22_000);
    expect($tx->approved_by_operator_id)->toBe($this->operator->id);

    $interaction = $this->customer->interactions()->first();
    expect($interaction)->not->toBeNull();
    expect($interaction->system_event)->toBe('wallet.refunded');
    expect($interaction->body)->toContain('Change on checkout day');
});

it('overpayment on RecordManualPayment credits the surplus to the wallet', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 100_000,
        'customer_id' => $this->customer->id,
    ]);

    $payment = app(RecordManualPayment::class)->handle(
        booking: $booking,
        operator: $this->operator,
        amount: 130_000,
        method: 'cash',
    );

    expect($payment)->not->toBeNull();
    expect($payment->amount)->toBe(100_000);
    expect($this->customer->fresh()->walletBalance())->toBe(30_000);

    $tx = CustomerWalletTransaction::query()->where('booking_id', $booking->id)->first();
    expect($tx)->not->toBeNull();
    expect($tx->meta['source'] ?? null)->toBe('overpayment_at_checkin');
});

it('PayBookingFromWallet settles outstanding + records a wallet Payment', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold(40_000)->create([
        'total_amount' => 120_000,
        'customer_id' => $this->customer->id,
    ]);

    app(WalletService::class)->topup($this->customer, 120_000, 'seed');

    $payment = app(PayBookingFromWallet::class)->handle($booking, $this->operator);

    expect($payment)->not->toBeNull();
    expect($payment->method)->toBe('wallet');
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
    expect($this->customer->fresh()->walletBalance())->toBe(120_000 - $payment->amount);

    $booking->refresh();
    expect((int) $booking->balance_due)->toBe(0);
    expect($booking->payment_status)->toBe(PaymentStatus::Succeeded);
});

it('PayBookingFromWallet caps at the outstanding total and wallet balance', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold(20_000)->create([
        'total_amount' => 100_000,
        'customer_id' => $this->customer->id,
    ]);
    app(WalletService::class)->topup($this->customer, 30_000, 'seed');

    // Only 30k in the wallet; outstanding is 80k. Should spend 30k.
    $payment = app(PayBookingFromWallet::class)->handle($booking, $this->operator);
    expect($payment->amount)->toBe(30_000);
    expect(Payment::query()->where('booking_id', $booking->id)->where('method', 'wallet')->sum('amount'))->toBe(30_000);
    expect($this->customer->fresh()->walletBalance())->toBe(0);
});
