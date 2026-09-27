<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Customers\WalletService;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'wallet-pdf']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager->value,
    ]);
    $this->customer = Customer::create([
        'name' => 'PDF Guest',
        'email' => 'pdf@x.test',
        'lifecycle' => CustomerLifecycle::Active,
    ]);
    app(WalletService::class)->topup($this->customer, 40_000, 'Cash top-up');
    app(WalletService::class)->spend($this->customer, 15_000, 'Bar tab');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('streams a PDF response for the customer wallet statement', function (): void {
    $this->actingAs($this->operator, 'web');

    $response = $this->get(route('tenant.customers.wallet.statement', $this->customer));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    $body = $response->getContent();
    expect(str_starts_with((string) $body, '%PDF'))->toBeTrue();
});
