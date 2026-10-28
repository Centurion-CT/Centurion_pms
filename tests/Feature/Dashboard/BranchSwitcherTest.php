<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'switch-branch']);
    $this->tenant->makeCurrent();
    $this->main = Property::factory()->for($this->tenant)->create(['name' => 'Lekki']);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('shows the branch switcher when the hotel has two branches', function (): void {
    Property::factory()->for($this->tenant)->create(['name' => 'Ikoyi']);

    $this->actingAs($this->manager, 'web')
        ->get('http://switch-branch.keverna.test/dashboard')
        ->assertOk()
        ->assertSee('data-testid="dash-branch-switcher"', false)
        ->assertSee('Ikoyi');
});

it('shows the branch in the sidebar and topbar with one branch, with a way to add one', function (): void {
    $this->actingAs($this->manager, 'web')
        ->get('http://switch-branch.keverna.test/dashboard')
        ->assertOk()
        ->assertSee('data-testid="dash-branch-switcher"', false)
        ->assertSee('data-testid="dash-topbar-branch"', false)
        ->assertSee('Lekki')
        ->assertSee('Add a branch');
});

it('hides add a branch from staff who cannot manage branches', function (): void {
    $desk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);
    $desk->branches()->attach($this->main->id);

    $this->actingAs($desk, 'web')
        ->get('http://switch-branch.keverna.test/dashboard')
        ->assertOk()
        ->assertSee('data-testid="dash-topbar-branch"', false)
        ->assertDontSee('Add a branch');
});
