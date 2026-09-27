<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\PropertyType;
use App\Livewire\Dashboard\Staff\OperatorPermissions;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Livewire\Livewire;

/**
 * Staff-to-branch access scoping. Empty pivot = all-branches access
 * (backwards compat). Once any branch is ticked, the operator is
 * restricted to that set. Managers always bypass restrictions.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'branch-access']);
    $this->tenant->makeCurrent();

    $this->lagos = Property::factory()->for($this->tenant)->create(['name' => 'Lagos', 'type' => PropertyType::Hotel]);
    $this->abuja = Property::factory()->for($this->tenant)->create(['name' => 'Abuja', 'type' => PropertyType::Hotel]);
    $this->portHarcourt = Property::factory()->for($this->tenant)->create(['name' => 'Port Harcourt', 'type' => PropertyType::Hotel]);

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'email_verified_at' => now(),
        'phone' => '+2348012345678',
        'phone_verified_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('operator with empty pivot has access to every branch (backwards compat)', function (): void {
    $staff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);

    expect($staff->hasAccessToBranch($this->lagos))->toBeTrue();
    expect($staff->hasAccessToBranch($this->abuja))->toBeTrue();
});

it('operator restricted to specific branches loses access to the others', function (): void {
    $staff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);
    $staff->branches()->sync([$this->lagos->id]);

    expect($staff->fresh()->hasAccessToBranch($this->lagos))->toBeTrue();
    expect($staff->fresh()->hasAccessToBranch($this->abuja))->toBeFalse();
});

it('Manager bypasses branch restrictions even with pivot rows', function (): void {
    // Weirdly-configured manager (restricted to Lagos via UI mistake)
    // — should still see Abuja because Manager role bypasses.
    $this->manager->branches()->sync([$this->lagos->id]);

    expect($this->manager->fresh()->hasAccessToBranch($this->lagos))->toBeTrue();
    expect($this->manager->fresh()->hasAccessToBranch($this->abuja))->toBeTrue();
});

it('PropertyContext::all() filters by operator access', function (): void {
    $staff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);
    $staff->branches()->sync([$this->lagos->id, $this->abuja->id]);

    $branches = app(PropertyContext::class)->all(operator: $staff->fresh());

    expect($branches->pluck('id')->all())->toBe([$this->lagos->id, $this->abuja->id]);
});

it('PropertyContext::current() snaps to first accessible when saved pick is out of scope', function (): void {
    $staff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
        'current_property_id' => $this->portHarcourt->id,
    ]);
    // Admin restricts them to only Lagos + Abuja after they were
    // already working in Port Harcourt.
    $staff->branches()->sync([$this->lagos->id, $this->abuja->id]);

    $current = app(PropertyContext::class)->current($staff->fresh(), $this->tenant);

    expect($current->id)->toBe($this->lagos->id);
});

it('PropertyContext::switchTo() refuses inaccessible branches', function (): void {
    $staff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);
    $staff->branches()->sync([$this->lagos->id]);

    $ok = app(PropertyContext::class)->switchTo($this->abuja, $staff->fresh());

    expect($ok)->toBeFalse();
    expect($staff->fresh()->current_property_id)->toBeNull();
});

it('switch endpoint no-ops when the operator lacks access to the target branch', function (): void {
    $staff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
        'email_verified_at' => now(),
        'phone' => '+2348012345678',
        'phone_verified_at' => now(),
    ]);
    $staff->branches()->sync([$this->lagos->id]);

    $this->actingAs($staff->fresh(), 'web');
    $this->post('http://branch-access.keverna.test/dashboard/branches/switch', [
        'property_id' => $this->abuja->id,
    ]);

    // Operator context stayed put (null, since they had no picked
    // branch prior).
    expect($staff->fresh()->current_property_id)->toBeNull();
});

it('Staff permissions page persists branch selections', function (): void {
    $staff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(OperatorPermissions::class, ['operator' => $staff->id])
        ->set('selectedBranchIds', [$this->lagos->id, $this->abuja->id])
        ->call('save');

    expect($staff->fresh()->branches()->pluck('properties.id')->all())
        ->toEqualCanonicalizing([$this->lagos->id, $this->abuja->id]);
});

it('branch access section hides for a Manager being edited', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(OperatorPermissions::class, ['operator' => $this->manager->id])
        ->assertViewHas('isManagerBeingEdited', true)
        ->assertDontSeeHtml('data-testid="branch-access-'.$this->lagos->slug.'"');
});

it('rejects foreign-tenant branch ids submitted through the form', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-branch']);
    $otherProperty = Property::factory()->for($otherTenant)->create();

    $staff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(OperatorPermissions::class, ['operator' => $staff->id])
        ->set('selectedBranchIds', [$this->lagos->id, $otherProperty->id])
        ->call('save');

    // Foreign id silently dropped; only the tenant-scoped one persists.
    expect($staff->fresh()->branches()->pluck('properties.id')->all())
        ->toBe([$this->lagos->id]);
});
