<?php

declare(strict_types=1);

use App\Livewire\Central\Chooser;
use App\Models\Customer;
use App\Models\IdentityAccount;
use App\Models\IdentityGuestLink;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\Identity\PostLoginRouter;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/**
 * Make a Customer on a tenant and return it. BelongsToTenant needs an
 * active tenant to create; we forget it after so subsequent creations
 * on other tenants aren't accidentally scoped.
 */
function makeCustomerOnTenant(Tenant $tenant, string $email = 'guest@x.test'): Customer
{
    $tenant->makeCurrent();
    $customer = Customer::create([
        'name' => 'Guest',
        'email' => $email,
        'lifecycle' => \App\Enums\CustomerLifecycle::Prospect,
    ]);
    Tenant::forgetCurrent();

    return $customer;
}

/**
 * Make an identity + a guest link to the given tenant. Returns [identity, link].
 *
 * @return array{IdentityAccount, IdentityGuestLink}
 */
function makeIdentityWithGuestLink(Tenant $tenant, string $email = 'guest-'): array
{
    $customer = makeCustomerOnTenant($tenant, "{$email}@x.test");
    $identity = IdentityAccount::create([
        'email' => "{$email}identity@x.test",
        'password' => Hash::make('pw'),
        'name' => 'Guest',
    ]);

    $link = IdentityGuestLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'claimed_at' => now(),
    ]);

    return [$identity, $link];
}

it('routes a guest-only identity (0 operator links, ≥1 guest link) to /reservations', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'g-only']);
    [$identity, ] = makeIdentityWithGuestLink($tenant, 'g-only');

    $result = (new PostLoginRouter)->resolve($identity);

    expect($result['kind'])->toBe('reservations');
});

it('routes a dual-role identity (has operator + guest links) to the chooser', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'dual-op']);
    $tenantB = Tenant::factory()->create(['slug' => 'dual-guest']);

    // Guest link on tenant B
    [$identity, ] = makeIdentityWithGuestLink($tenantB, 'dual');

    // Operator link on tenant A for the same identity
    $tenantA->makeCurrent();
    $operator = Operator::factory()->for($tenantA)->create();
    Tenant::forgetCurrent();
    IdentityOperatorLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenantA->id,
        'operator_id' => $operator->id,
    ]);

    $result = (new PostLoginRouter)->resolve($identity);

    expect($result['kind'])->toBe('choose');
});

it('shows the "My reservations" card in the chooser when the identity has guest links', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'card-op']);
    $tenantB = Tenant::factory()->create(['slug' => 'card-guest']);

    [$identity, ] = makeIdentityWithGuestLink($tenantB, 'card');

    $tenantA->makeCurrent();
    $operator = Operator::factory()->for($tenantA)->create();
    Tenant::forgetCurrent();
    IdentityOperatorLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenantA->id,
        'operator_id' => $operator->id,
    ]);

    Livewire::actingAs($identity, 'identity')
        ->test(Chooser::class)
        ->assertViewHas('hasGuestLinks', true)
        ->assertSee('My reservations');
});

it('does not show the "My reservations" card when the identity has zero guest links', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'no-guest']);
    $tenant->makeCurrent();
    $operator = Operator::factory()->for($tenant)->create();
    Tenant::forgetCurrent();

    $identity = IdentityAccount::create([
        'email' => 'no-guest@x.test',
        'password' => Hash::make('pw'),
        'name' => 'NoGuest',
    ]);
    IdentityOperatorLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenant->id,
        'operator_id' => $operator->id,
    ]);
    // Add a second operator link so the router lands on chooser (single-op
    // would skip the chooser altogether).
    $tenant2 = Tenant::factory()->create(['slug' => 'no-guest-2']);
    $tenant2->makeCurrent();
    $op2 = Operator::factory()->for($tenant2)->create();
    Tenant::forgetCurrent();
    IdentityOperatorLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenant2->id,
        'operator_id' => $op2->id,
    ]);

    Livewire::actingAs($identity, 'identity')
        ->test(Chooser::class)
        ->assertViewHas('hasGuestLinks', false)
        ->assertDontSee('My reservations');
});

it('exposes guest links via the relationship and lets them reach Customer across tenant scope', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'rel-guest']);
    [$identity, $link] = makeIdentityWithGuestLink($tenant, 'rel');

    expect($identity->guestLinks)->toHaveCount(1);
    // No current tenant — the customer relation on the link must bypass
    // TenantScope so the guest dashboard can read across tenants.
    expect(\App\Models\Tenant::checkCurrent())->toBeFalse();
    expect($link->customer->email)->toBe('rel@x.test');
    expect($link->tenant->slug)->toBe('rel-guest');
});
