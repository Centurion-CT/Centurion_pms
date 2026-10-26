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

it('sends the root to the staff sign-in, even with several branches', function (): void {
    Property::factory()->live()->for($this->tenant)->create(['name' => 'Citistay Abuja']);
    Property::factory()->live()->for($this->tenant)->create(['name' => 'Citistay Lagos']);

    $this->get('http://multi-branch.keverna.test/')
        ->assertRedirect(route('tenant.login'));
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
    $only = Property::factory()->live()->for($this->tenant)->create(['name' => 'Only Branch']);

    $response = $this->get('http://multi-branch.keverna.test/branches/'.$only->slug);

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
