<?php

declare(strict_types=1);

use App\Enums\PropertyType;
use App\Livewire\Onboarding\PropertyBasics;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'props-test',
        'name' => 'Maitama Suites',
    ]);
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->unverified()->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('prefills the name field from the tenant name', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyBasics::class)
        ->assertSet('name', 'Maitama Suites');
});

it('persists the property on save and advances to step 2', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyBasics::class)
        ->set('name', 'Maitama Suites')
        ->set('type', 'serviced')
        ->set('address', '12 Ahmadu Bello Way, Maitama')
        ->set('city', 'Abuja')
        ->set('state', 'FCT')
        ->set('description', 'A serviced apartment complex.')
        ->call('save')
        ->assertRedirect(route('tenant.onboarding.email'));

    $property = $this->tenant->property()->first();
    expect($property)->not->toBeNull();
    expect($property->name)->toBe('Maitama Suites');
    expect($property->type)->toBe(PropertyType::ServicedApartment);
    expect($property->address)->toBe('12 Ahmadu Bello Way, Maitama');
    expect($property->is_live)->toBeFalse();
});

it('rejects an invalid property type', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyBasics::class)
        ->set('name', 'X')
        ->set('type', 'spaceship')
        ->set('address', '12 Test Rd')
        ->call('save')
        ->assertHasErrors(['type']);
});

it('rejects missing required fields', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyBasics::class)
        ->set('name', '')
        ->set('type', '')
        ->set('address', '')
        ->call('save')
        ->assertHasErrors(['name', 'type', 'address']);
});

it('updates the existing property when called twice (idempotent step 1)', function (): void {
    Property::factory()->for($this->tenant)->create([
        'name' => 'Old Name',
        'type' => PropertyType::Hotel,
        'address' => 'Old Address',
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyBasics::class)
        ->assertSet('name', 'Old Name')
        ->set('name', 'New Name')
        ->set('type', 'short_let')
        ->set('address', 'New Address')
        ->call('save');

    expect($this->tenant->property()->count())->toBe(1);
    $property = $this->tenant->property()->first();
    expect($property->name)->toBe('New Name');
    expect($property->type)->toBe(PropertyType::ShortLet);
});

it('the property is tenant-scoped — cannot leak across tenants', function (): void {
    Property::factory()->for($this->tenant)->create();

    $otherTenant = Tenant::factory()->create();
    Tenant::forgetCurrent();
    $otherTenant->makeCurrent();

    expect($otherTenant->property()->count())->toBe(0);
    expect(Property::count())->toBe(0);
});
