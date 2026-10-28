<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Branches\Index as BranchesIndex;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Livewire\Livewire;

/**
 * Dashboard multi-branch surface — PropertyContext resolution,
 * the sidebar switcher's POST endpoint, and the "Add a branch" flow.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'branches-dash']);
    $this->tenant->makeCurrent();
    // Active trial so the global billing write-guard doesn't throw
    // when the switch endpoint saves the operator's picked branch.

    $this->main = Property::factory()->for($this->tenant)->create(['name' => 'Main']);
    $this->lagos = Property::factory()->for($this->tenant)->create(['name' => 'Lagos']);
    // Onboarding-complete operator so RequireCompleteOnboarding
    // doesn't bounce dashboard requests during the switch test.
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'email_verified_at' => now(),
        'phone' => '+2348012345678',
        'phone_verified_at' => now(),
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('PropertyContext falls back to the first property when the operator has no pick', function (): void {
    $context = app(PropertyContext::class);
    $picked = $context->current($this->manager, $this->tenant);

    expect($picked)->not->toBeNull();
    expect($picked->id)->toBe($this->main->id);
});

it('PropertyContext honours the operator saved pick', function (): void {
    $this->manager->forceFill(['current_property_id' => $this->lagos->id])->save();

    $picked = app(PropertyContext::class)->current($this->manager->fresh(), $this->tenant);

    expect($picked->id)->toBe($this->lagos->id);
});

it('PropertyContext ignores a saved pick belonging to another tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-t']);
    $otherProp = Property::factory()->for($otherTenant)->create();
    $this->manager->forceFill(['current_property_id' => $otherProp->id])->save();

    $picked = app(PropertyContext::class)->current($this->manager->fresh(), $this->tenant);

    // Falls back to the tenant's first property, not the foreign one.
    expect($picked->id)->toBe($this->main->id);
});

it('the /dashboard/branches/switch endpoint persists the pick', function (): void {
    $this->actingAs($this->manager, 'web');

    $response = $this->post('http://branches-dash.keverna.test/dashboard/branches/switch', [
        'property_id' => $this->lagos->id,
        'return_to' => 'http://branches-dash.keverna.test/dashboard/rooms',
    ]);

    $response->assertRedirect('http://branches-dash.keverna.test/dashboard/rooms');
    expect($this->manager->fresh()->current_property_id)->toBe($this->lagos->id);
});

it('the switch endpoint refuses a foreign-tenant property_id', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'ot-fs']);
    $otherProp = Property::factory()->for($otherTenant)->create();

    $this->actingAs($this->manager, 'web');
    $response = $this->post('http://branches-dash.keverna.test/dashboard/branches/switch', [
        'property_id' => $otherProp->id,
    ]);

    // Falls back to dashboard redirect; operator context stays put.
    $response->assertRedirect(route('tenant.dashboard'));
    expect($this->manager->fresh()->current_property_id)->toBeNull();
});

it('Branches Index create() adds a new property and switches into it', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(BranchesIndex::class)
        ->set('newName', 'Abuja Central')
        ->set('newAddress', '10 Wuse Street')
        ->set('newCity', 'Abuja')
        ->set('newType', 'hotel')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('tenant.branches.index'));

    $branch = Property::query()->where('name', 'Abuja Central')->first();
    expect($branch)->not->toBeNull();
    expect($branch->tenant_id)->toBe($this->tenant->id);
    expect($branch->slug)->toBe('abuja-central');
    expect($branch->is_live)->toBeFalse();

    // Operator's context snapped to the new branch.
    expect($this->manager->fresh()->current_property_id)->toBe($branch->id);
});

it('auto-generates a numeric suffix on slug collision within a tenant', function (): void {
    Property::factory()->for($this->tenant)->create(['name' => 'Downtown']);
    Property::factory()->for($this->tenant)->create(['name' => 'Downtown']);
    Property::factory()->for($this->tenant)->create(['name' => 'Downtown']);

    $slugs = Property::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('name', 'Downtown')
        ->pluck('slug')->all();

    expect($slugs)->toBe(['downtown', 'downtown-2', 'downtown-3']);
});
