<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->propertyA = Property::factory()->for($this->tenant)->create(['name' => 'Grand Palace']);
    $this->propertyB = Property::factory()->for($this->tenant)->create(['name' => 'Ikoyi Loft']);

    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->operator->current_property_id = $this->propertyA->id;
    $this->operator->save();

    Auth::guard('web')->login($this->operator);

    // Seed a customer at each property with distinguishable data.
    Customer::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->propertyA->id,
        'name'  => 'Ada Alpha',
        'email' => 'ada@alpha.test',
        'phone' => '+2348001',
    ]);
    Customer::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->propertyB->id,
        'name'  => 'Ada Beta',
        'email' => 'ada@beta.test',
        'phone' => '+2348002',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('walk-in typeahead only shows customers from the operator\'s current branch', function (): void {
    // Directly exercise the query pattern HasCustomerLookup uses.
    $q = Customer::query()->forCurrentProperty()
        ->where(function ($q) { $q->where('name', 'like', '%Ada%')
            ->orWhere('email', 'like', '%Ada%')
            ->orWhere('phone', 'like', '%Ada%'); })
        ->limit(6)
        ->get();
    expect($q->pluck('name')->all())->toBe(['Ada Alpha']);
});

it('walk-in typeahead follows the operator branch flip', function (): void {
    $this->operator->current_property_id = $this->propertyB->id;
    $this->operator->save();
    // Re-login so PropertyContext picks the fresh operator row.
    Auth::guard('web')->login($this->operator->fresh());

    $q = Customer::query()->forCurrentProperty()
        ->where(function ($q) { $q->where('name', 'like', '%Ada%')
            ->orWhere('email', 'like', '%Ada%')
            ->orWhere('phone', 'like', '%Ada%'); })
        ->limit(6)
        ->get();
    expect($q->pluck('name')->all())->toBe(['Ada Beta']);
});

it('pickedCustomer refuses a stale id that no longer belongs to the branch', function (): void {
    $adaBId = Customer::query()->where('email', 'ada@beta.test')->value('id');
    // Operator is in property A; try picking property B's customer id.
    $picked = Customer::query()->forCurrentProperty()->find($adaBId);
    expect($picked)->toBeNull();
});

it('marketplace checkout autofill is intentionally cross-tenant + not affected', function (): void {
    // A guest whose phone is in property B's CRM. The marketplace
    // stays checkout should still autofill their name/email even
    // when the operator context is elsewhere — this is the ONE path
    // that stays deliberately cross-branch (see the code comment).
    // Sanity check that we haven't broken the direct query used
    // there (Customer::query()->withoutGlobalScope(TenantScope)).
    $match = Customer::query()
        ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
        ->where('phone', '+2348002')
        ->first();

    expect($match?->name)->toBe('Ada Beta');
});
