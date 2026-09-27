<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\Tenant;

/**
 * Guest-facing multi-branch smoke tests. The root URL branches on
 * how many properties the tenant has:
 *   - 1 property   → renders the landing (backwards compat)
 *   - 2+ properties → renders the "Choose a location" chooser
 *
 * Each individual branch is browsable at /branches/{slug}.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'multi-branch', 'name' => 'Citistay']);
});

it('shows the branches chooser at the root when the tenant has multiple properties', function (): void {
    Property::factory()->live()->for($this->tenant)->create([
        'name' => 'Citistay Abuja',
        'city' => 'Abuja',
    ]);
    Property::factory()->live()->for($this->tenant)->create([
        'name' => 'Citistay Lagos',
        'city' => 'Lagos',
    ]);

    $response = $this->get('http://multi-branch.keverna.test/');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="branches-chooser"');
    $response->assertSee('Citistay Abuja');
    $response->assertSee('Citistay Lagos');
    $response->assertSeeHtml('href="'.route('tenant.branch.show', ['property' => Property::first()->slug]).'"');
});

it('still shows the single-branch landing at root for pre-multi-property tenants', function (): void {
    Property::factory()->live()->for($this->tenant)->create([
        'name' => 'Citistay Main',
    ]);

    $response = $this->get('http://multi-branch.keverna.test/');

    $response->assertOk();
    $response->assertDontSeeHtml('data-testid="branches-chooser"');
    $response->assertSeeHtml('data-testid="hero"');
});

it('renders a specific branch at /branches/{slug}', function (): void {
    Property::factory()->live()->for($this->tenant)->create(['name' => 'Citistay Abuja']);
    $lagos = Property::factory()->live()->for($this->tenant)->create(['name' => 'Citistay Lagos']);

    $response = $this->get('http://multi-branch.keverna.test/branches/'.$lagos->slug);

    $response->assertOk();
    $response->assertSeeHtml('data-testid="hero"');
    $response->assertSee('Citistay Lagos');
});

it('renders the guest branch switcher on a branch landing when multiple exist', function (): void {
    Property::factory()->live()->for($this->tenant)->create(['name' => 'Citistay Abuja']);
    $lagos = Property::factory()->live()->for($this->tenant)->create(['name' => 'Citistay Lagos']);

    $response = $this->get('http://multi-branch.keverna.test/branches/'.$lagos->slug);

    $response->assertOk();
    $response->assertSeeHtml('data-testid="branch-switcher"');
    $response->assertSeeHtml('data-testid="branch-switcher-item-'.$lagos->slug.'"');
});

it('omits the switcher entirely for single-branch tenants', function (): void {
    Property::factory()->live()->for($this->tenant)->create(['name' => 'Only Branch']);

    $response = $this->get('http://multi-branch.keverna.test/');

    $response->assertOk();
    $response->assertDontSeeHtml('data-testid="branch-switcher"');
});

it('404s on a slug that belongs to another tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-org']);
    $otherProp = Property::factory()->live()->for($otherTenant)->create(['name' => 'Other']);
    Property::factory()->live()->for($this->tenant)->create(['name' => 'Mine']);

    // A branch slug from another tenant is not resolvable under this
    // subdomain — BelongsToTenant global scope on Property enforces it.
    $response = $this->get('http://multi-branch.keverna.test/branches/'.$otherProp->slug);
    $response->assertNotFound();
});
