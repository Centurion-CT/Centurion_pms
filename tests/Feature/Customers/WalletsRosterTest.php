<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Finance\Wallets;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'wallets-roster']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager->value,
    ]);

    $this->funded = Customer::create(['name' => 'Big Wallet', 'email' => 'big@x.test', 'lifecycle' => CustomerLifecycle::Active]);
    $this->smallFunded = Customer::create(['name' => 'Small Wallet', 'email' => 'small@x.test', 'lifecycle' => CustomerLifecycle::Active]);
    $this->emptyWallet = Customer::create(['name' => 'Empty Guest', 'email' => 'empty@x.test', 'lifecycle' => CustomerLifecycle::Active]);
    $this->noWallet = Customer::create(['name' => 'No Wallet', 'email' => 'no@x.test', 'lifecycle' => CustomerLifecycle::Active]);

    app(WalletService::class)->topup($this->funded, 200_000, 'seed');
    app(WalletService::class)->topup($this->smallFunded, 5_000, 'seed');
    // emptyWallet had a wallet touched: top-up then spend-all
    app(WalletService::class)->topup($this->emptyWallet, 10_000, 'seed');
    app(WalletService::class)->spend($this->emptyWallet, 10_000, 'spent all');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('shows only funded wallets by default and sorts by highest balance', function (): void {
    $this->actingAs($this->operator, 'web');

    Livewire::test(Wallets::class)
        ->assertSee('Guest wallets')
        ->assertSee('Big Wallet')
        ->assertSee('Small Wallet')
        ->assertDontSee('Empty Guest')      // filtered out — balance = 0
        ->assertDontSee('No Wallet')        // no wallet row at all
        ->assertSet('balanceFilter', 'funded')
        ->assertSet('sort', 'balance_desc');
});

it('KPI reports total on deposit + funded count', function (): void {
    $this->actingAs($this->operator, 'web');

    Livewire::test(Wallets::class)
        ->assertViewHas('totalOnDeposit', 205_000)   // 200k + 5k (emptyWallet is 0, excluded)
        ->assertViewHas('fundedCount', 2)
        ->assertViewHas('totalWallets', 3);          // 3 rows exist including the emptied one
});

it('switching to All shows the emptied wallet too', function (): void {
    $this->actingAs($this->operator, 'web');

    Livewire::test(Wallets::class)
        ->set('balanceFilter', 'all')
        ->assertSee('Empty Guest')
        ->assertSee('Big Wallet');
});

it('search filters by name / email / phone', function (): void {
    $this->actingAs($this->operator, 'web');

    Livewire::test(Wallets::class)
        ->set('search', 'small@x.test')
        ->assertSee('Small Wallet')
        ->assertDontSee('Big Wallet');
});
