<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Enums\CustomerWalletTransactionType;
use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\CustomerWalletTransaction;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\Customers\WalletService;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'wallet-svc']);
    $this->tenant->makeCurrent();
    $this->customer = Customer::create([
        'name' => 'Ada Guest',
        'email' => 'ada@x.test',
        'lifecycle' => CustomerLifecycle::Active,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create(['name' => 'Op W']);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('creates a wallet lazily on first ensure', function (): void {
    $svc = app(WalletService::class);

    expect(CustomerWallet::query()->count())->toBe(0);
    $wallet = $svc->ensureWallet($this->customer);
    expect(CustomerWallet::query()->count())->toBe(1);
    expect($wallet->balance_naira)->toBe(0);
    expect($wallet->tenant_id)->toBe($this->tenant->id);
});

it('records a topup and writes a matching ledger row', function (): void {
    $svc = app(WalletService::class);

    $tx = $svc->topup(
        customer: $this->customer,
        amount: 50_000,
        description: 'Cash at desk',
        paymentMethod: 'cash',
        causer: $this->operator,
    );

    expect($tx->type)->toBe(CustomerWalletTransactionType::Topup);
    expect($tx->amount_naira)->toBe(50_000);
    expect($tx->balance_after_naira)->toBe(50_000);
    expect($tx->payment_method)->toBe('cash');
    expect($this->customer->fresh()->walletBalance())->toBe(50_000);
});

it('spends from the wallet and rejects an overspend', function (): void {
    $svc = app(WalletService::class);
    $svc->topup($this->customer, 20_000, 'seed');

    $tx = $svc->spend($this->customer, 8_000, 'coffee');
    expect($tx->amount_naira)->toBe(-8_000);
    expect($tx->balance_after_naira)->toBe(12_000);
    expect($this->customer->fresh()->walletBalance())->toBe(12_000);

    expect(fn () => $svc->spend($this->customer, 20_000, 'too much'))
        ->toThrow(RuntimeException::class, 'Insufficient');
});

it('spendUpTo caps to available balance and returns null on empty wallet', function (): void {
    $svc = app(WalletService::class);
    $svc->topup($this->customer, 5_000, 'seed');

    $partial = $svc->spendUpTo($this->customer, 12_000, 'overstay');
    expect($partial)->not->toBeNull();
    expect($partial->amount_naira)->toBe(-5_000);
    expect($this->customer->fresh()->walletBalance())->toBe(0);

    $second = $svc->spendUpTo($this->customer, 5_000, 'overstay day 2');
    expect($second)->toBeNull();
});

it('refund debits the wallet and requires a positive amount', function (): void {
    $svc = app(WalletService::class);
    $svc->topup($this->customer, 40_000, 'seed');

    $tx = $svc->refund(
        customer: $this->customer,
        amount: 15_000,
        description: 'Cash back at checkout',
        paymentMethod: 'cash',
        approvedByOperatorId: $this->operator->id,
    );

    expect($tx->type)->toBe(CustomerWalletTransactionType::Refund);
    expect($tx->balance_after_naira)->toBe(25_000);
    expect($tx->approved_by_operator_id)->toBe($this->operator->id);
    expect(fn () => $svc->refund($this->customer, 0, 'zero'))->toThrow(RuntimeException::class);
});

it('adjust accepts signed amounts and blocks deficit', function (): void {
    $svc = app(WalletService::class);
    $svc->topup($this->customer, 10_000, 'seed');

    $svc->adjust($this->customer, -3_000, 'ATM shortage');
    expect($this->customer->fresh()->walletBalance())->toBe(7_000);

    expect(fn () => $svc->adjust($this->customer, -100_000, 'wipe'))
        ->toThrow(RuntimeException::class, 'Wallet balance cannot go negative');
});

it('ledger row count and balance stay in sync across many operations', function (): void {
    $svc = app(WalletService::class);
    $svc->topup($this->customer, 100_000, 'seed');
    $svc->spend($this->customer, 20_000, 'a');
    $svc->spend($this->customer, 15_000, 'b');
    $svc->topup($this->customer, 5_000, 'top-up');

    $sum = (int) CustomerWalletTransaction::query()
        ->where('customer_id', $this->customer->id)
        ->sum('amount_naira');

    expect($sum)->toBe($this->customer->fresh()->walletBalance());
    expect($sum)->toBe(70_000);
});
