<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Customers\Index as CustomersIndex;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'crm-wallet']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager->value,
    ]);

    $this->funded = Customer::create(['name' => 'Alice Rich', 'email' => 'a@x.test', 'lifecycle' => CustomerLifecycle::Active]);
    $this->broke = Customer::create(['name' => 'Bob Broke', 'email' => 'b@x.test', 'lifecycle' => CustomerLifecycle::Active]);

    app(WalletService::class)->topup($this->funded, 75_000, 'seed');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders the wallet column with the customers balance', function (): void {
    $this->actingAs($this->operator, 'web');

    Livewire::test(CustomersIndex::class)
        ->assertSeeHtml('data-testid="customer-wallet-'.$this->funded->id.'"')
        ->assertSee('₦75,000');
});

it('has-wallet chip filters to funded customers only', function (): void {
    $this->actingAs($this->operator, 'web');

    Livewire::test(CustomersIndex::class)
        ->set('walletFilter', '1')
        ->assertSee('Alice Rich')
        ->assertDontSee('Bob Broke');
});

it('wallet_desc sort orders by balance descending', function (): void {
    $bigger = Customer::create(['name' => 'Zeta Rich', 'email' => 'z@x.test', 'lifecycle' => CustomerLifecycle::Active]);
    app(WalletService::class)->topup($bigger, 500_000, 'seed');

    $this->actingAs($this->operator, 'web');

    $customers = Livewire::test(CustomersIndex::class)
        ->set('sort', 'wallet_desc')
        ->viewData('customers');

    $names = collect($customers->items())->map(fn ($c) => $c->name)->values()->all();
    expect($names[0])->toBe('Zeta Rich');
    expect($names[1])->toBe('Alice Rich');
});

it('exposes the has-wallet count in the head-row chips', function (): void {
    $this->actingAs($this->operator, 'web');

    Livewire::test(CustomersIndex::class)
        ->assertViewHas('counts', function (array $counts): bool {
            return $counts['wallet'] === 1;
        });
});
