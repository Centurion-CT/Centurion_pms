<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Discount;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->propertyA = Property::factory()->for($this->tenant)->create(['name' => 'A']);
    $this->propertyB = Property::factory()->for($this->tenant)->create(['name' => 'B']);

    // Log in an operator and pin them to Property A.
    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->operator->current_property_id = $this->propertyA->id;
    $this->operator->save();
    auth('web')->login($this->operator);
});

afterEach(fn () => Tenant::forgetCurrent());

it('auto-stamps property_id from the operator\'s current branch on Customer create', function (): void {
    $customer = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name'  => 'Ada',
        'phone' => '+2348012345678',
    ]);

    expect($customer->property_id)->toBe($this->propertyA->id);
});

it('scopeForCurrentProperty filters records to only the operator\'s branch', function (): void {
    // Seed one customer for each property.
    Customer::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id, 'name' => 'Ada', 'phone' => '+234800']);
    Customer::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyB->id, 'name' => 'Bola', 'phone' => '+234801']);

    // A-context sees only Ada.
    $names = Customer::query()->forCurrentProperty()->pluck('name')->all();
    expect($names)->toBe(['Ada']);

    // Flip to Property B.
    $this->operator->current_property_id = $this->propertyB->id;
    $this->operator->save();
    // The observer scoped to the operator's stored current_property_id
    // via PropertyContext — refresh to bypass a possibly-cached model.
    $namesB = Customer::query()->forCurrentProperty()->pluck('name')->all();
    expect($namesB)->toBe(['Bola']);
});

it('respects an explicit property_id and does not overwrite it', function (): void {
    // Explicitly stamp B while the operator is in A.
    $c = Customer::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->propertyB->id,
        'name'  => 'Direct',
        'phone' => '+234802',
    ]);
    expect($c->property_id)->toBe($this->propertyB->id);
});

it('scopeForProperty accepts a Property instance or an int', function (): void {
    Customer::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id, 'name' => 'Ada', 'phone' => '+234800']);
    Customer::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyB->id, 'name' => 'Bola', 'phone' => '+234801']);

    expect(Customer::query()->forProperty($this->propertyA)->count())->toBe(1);
    expect(Customer::query()->forProperty($this->propertyB->id)->count())->toBe(1);
});

it('the customers index scope toggle: branch vs all properties', function (): void {
    Customer::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id, 'name' => 'Ada', 'phone' => '+234800']);
    Customer::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyB->id, 'name' => 'Bola', 'phone' => '+234801']);

    Livewire\Livewire::test(\App\Livewire\Dashboard\Customers\Index::class)
        ->assertViewHas('showScopeToggle', true)
        ->assertViewHas('scope', 'branch')
        ->tap(function ($c) {
            $customers = $c->viewData('customers');
            expect($customers->pluck('name')->all())->toBe(['Ada']);
        })
        ->set('propertyScope', 'all')
        ->tap(function ($c) {
            $customers = $c->viewData('customers');
            expect($customers->pluck('name')->sort()->values()->all())->toBe(['Ada', 'Bola']);
        });
});

it('allows the same email under a tenant across two properties (per-branch CRM)', function (): void {
    // Property A: create Ada with email ada@x.test
    Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->propertyA->id,
        'name' => 'Ada A', 'email' => 'ada@x.test', 'phone' => '+2348001',
    ]);

    // Same email, different property under the same tenant should NOT
    // collide with the old (tenant, email) unique — the new key is
    // (tenant, property, email).
    $adaB = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->propertyB->id,
        'name' => 'Ada B', 'email' => 'ada@x.test', 'phone' => '+2348002',
    ]);

    expect($adaB->exists)->toBeTrue();
    expect(Customer::query()->where('email', 'ada@x.test')->count())->toBe(2);
});

it('still blocks a duplicate email under the same (tenant, property)', function (): void {
    Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->propertyA->id,
        'name' => 'Ada', 'email' => 'ada@x.test', 'phone' => '+2348001',
    ]);

    $threw = false;
    try {
        Customer::query()->create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->propertyA->id,
            'name' => 'Duplicate Ada', 'email' => 'ada@x.test', 'phone' => '+2348099',
        ]);
    } catch (\Illuminate\Database\QueryException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

it('applies to Discount + inherits the same behaviour', function (): void {
    $d = Discount::query()->create([
        'tenant_id' => $this->tenant->id,
        'name'      => 'Weekend',
        'code'      => 'WEEK',
        'type'      => 'percent',
        'value'     => 10,
        'active'    => true,
    ]);
    expect($d->property_id)->toBe($this->propertyA->id);
});
