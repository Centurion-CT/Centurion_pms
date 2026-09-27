<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Settings\PropertyForm;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Keverna Stays marketplace listing toggle - the opt-out bit +
 * globally-unique public_slug that surfaces the property at
 * keverna.com/stays/{public_slug}.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'stays-mkt']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_live' => true,
        'is_listed_publicly' => true,
        'public_slug' => 'stays-mkt-property',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
});

it('mounts with the current listing flag + slug', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSet('isListedPublicly', true)
        ->assertSet('publicSlug', 'stays-mkt-property');
});

it('flips the property off the marketplace', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('isListedPublicly', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->property->fresh()->is_listed_publicly)->toBeFalse();
});

it('rejects a slug that is already taken by another property', function (): void {
    // A different tenant already owns "grand-plaza".
    $otherTenant = Tenant::factory()->create(['slug' => 'other-tenant']);
    Property::factory()->for($otherTenant)->create([
        'is_listed_publicly' => true,
        'public_slug' => 'grand-plaza',
    ]);
    $this->tenant->makeCurrent();

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('publicSlug', 'grand-plaza')
        ->call('save')
        ->assertHasErrors(['publicSlug' => 'unique']);

    expect($this->property->fresh()->public_slug)->toBe('stays-mkt-property');
});

it('rejects a slug with uppercase or spaces', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('publicSlug', 'Grand Plaza Abuja')
        ->call('save')
        ->assertHasErrors(['publicSlug' => 'regex']);
});

it('accepts a fresh valid slug and persists it', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('publicSlug', 'grand-plaza-abuja')
        ->call('save')
        ->assertHasNoErrors();

    expect(Property::query()
        ->withoutGlobalScope(TenantScope::class)
        ->find($this->property->id)
        ->public_slug)->toBe('grand-plaza-abuja');
});

it('renders the marketplace fieldset + toggle in the view', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSeeHtml('data-testid="marketplace-fieldset"')
        ->assertSeeHtml('data-testid="property-is-listed-publicly"')
        ->assertSee('Keverna Stays marketplace');
});
