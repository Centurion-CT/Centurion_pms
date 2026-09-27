<?php

declare(strict_types=1);

use App\Actions\Auth\RegisterTenantOperator;
use App\Enums\SubscriptionPlan;
use App\Livewire\Auth\Register;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Livewire\Livewire;

/**
 * Shape B tier wiring (docs/unified-login and the memo's tiered pricing).
 * Register flow derives SubscriptionPlan from unit_count; ?plan= URL
 * param only seeds a sensible default. Enterprise self-serve is refused.
 */

it('maps unit_count to the right tier via SubscriptionPlan::fromUnitCount', function (): void {
    expect(SubscriptionPlan::fromUnitCount(1))->toBe(SubscriptionPlan::Starter);
    expect(SubscriptionPlan::fromUnitCount(5))->toBe(SubscriptionPlan::Starter);
    expect(SubscriptionPlan::fromUnitCount(6))->toBe(SubscriptionPlan::Growth);
    expect(SubscriptionPlan::fromUnitCount(20))->toBe(SubscriptionPlan::Growth);
    expect(SubscriptionPlan::fromUnitCount(21))->toBe(SubscriptionPlan::Pro);
    expect(SubscriptionPlan::fromUnitCount(50))->toBe(SubscriptionPlan::Pro);
    expect(SubscriptionPlan::fromUnitCount(51))->toBe(SubscriptionPlan::Enterprise);
    expect(SubscriptionPlan::fromUnitCount(1000))->toBe(SubscriptionPlan::Enterprise);
});

it('reports self-serve correctly (Enterprise excluded)', function (): void {
    expect(SubscriptionPlan::Starter->isSelfServe())->toBeTrue();
    expect(SubscriptionPlan::Growth->isSelfServe())->toBeTrue();
    expect(SubscriptionPlan::Pro->isSelfServe())->toBeTrue();
    expect(SubscriptionPlan::Enterprise->isSelfServe())->toBeFalse();
    expect(SubscriptionPlan::Trial->isSelfServe())->toBeFalse();
});

it('seeds unit_count from the ?plan= URL param on mount', function (): void {
    Livewire::withQueryParams(['plan' => 'starter'])
        ->test(Register::class)
        ->assertSet('unit_count', 1);

    Livewire::withQueryParams(['plan' => 'growth'])
        ->test(Register::class)
        ->assertSet('unit_count', 8);

    Livewire::withQueryParams(['plan' => 'pro'])
        ->test(Register::class)
        ->assertSet('unit_count', 25);

    Livewire::withQueryParams(['plan' => 'enterprise'])
        ->test(Register::class)
        ->assertSet('unit_count', 51);
});

it('defaults to Growth when no plan is in the URL (matches Register::planParam default)', function (): void {
    Livewire::test(Register::class)->assertSet('unit_count', 8);
});

it('stores plan_id + expected_units on the tenant when the operator signs up', function (): void {
    Livewire::withQueryParams(['plan' => 'pro'])
        ->test(Register::class)
        ->set('name', 'Grace')
        ->set('email', 'grace@pro.test')
        ->set('property_name', 'Pro Suites')
        ->set('unit_count', 25)
        ->set('password', 'pro-secret-9')
        ->set('password_confirmation', 'pro-secret-9')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    $tenant = Tenant::query()->where('slug', 'pro-suites')->first();

    expect($tenant->plan_id)->toBe(SubscriptionPlan::Pro->value);
    expect($tenant->expected_units)->toBe(25);
});

it('picks the tier from unit_count even when it disagrees with the ?plan= URL param', function (): void {
    // Landed on Starter tier CTA but entered 30 units in the form.
    // The number wins — that's the source of truth.
    Livewire::withQueryParams(['plan' => 'starter'])
        ->test(Register::class)
        ->set('name', 'Overshoot')
        ->set('email', 'os@x.test')
        ->set('property_name', 'Overshoot Inn')
        ->set('unit_count', 30)
        ->set('password', 'over-shoot-9')
        ->set('password_confirmation', 'over-shoot-9')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    $tenant = Tenant::query()->where('slug', 'overshoot-inn')->first();
    expect($tenant->plan_id)->toBe(SubscriptionPlan::Pro->value);
});

it('creates the Subscription as Trial regardless of the intended tier (paid conversion is later)', function (): void {
    Livewire::withQueryParams(['plan' => 'pro'])
        ->test(Register::class)
        ->set('name', 'Grace')
        ->set('email', 'trial-check@pro.test')
        ->set('property_name', 'Trial Check Suites')
        ->set('unit_count', 25)
        ->set('password', 'trial-check-9')
        ->set('password_confirmation', 'trial-check-9')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    $tenant = Tenant::query()->where('slug', 'trial-check-suites')->first();
    $sub = Subscription::query()->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->id)->first();

    expect($sub->plan)->toBe(SubscriptionPlan::Trial);
    expect($sub->amount)->toBe(0);
});

it('refuses self-serve registration when unit_count places the operator on Enterprise', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Big Group')
        ->set('email', 'group@big.test')
        ->set('property_name', 'Big Group Holdings')
        ->set('unit_count', 100)
        ->set('password', 'big-group-9')
        ->set('password_confirmation', 'big-group-9')
        ->set('license_code', issuedLicenseCode())
        ->call('register')
        ->assertHasErrors(['unit_count']);

    expect(Tenant::query()->where('slug', 'big-group-holdings')->exists())->toBeFalse();
});

it('rejects invalid unit_count values', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Zero')
        ->set('email', 'zero@x.test')
        ->set('property_name', 'Zero Inn')
        ->set('unit_count', 0)
        ->set('password', 'zero-nine-9')
        ->set('password_confirmation', 'zero-nine-9')
        ->set('license_code', issuedLicenseCode())
        ->call('register')
        ->assertHasErrors(['unit_count']);
});

it('accepts plan + expected_units on the action directly (internal-callers path)', function (): void {
    $action = app(RegisterTenantOperator::class);

    $result = $action->handle([
        'name' => 'Direct',
        'email' => 'direct@x.test',
        'property_name' => 'Direct Inn',
        'phone' => null,
        'password' => 'direct-9',
        'expected_units' => 12,
        'plan' => SubscriptionPlan::Growth,
    ]);

    expect($result->tenant->plan_id)->toBe(SubscriptionPlan::Growth->value);
    expect($result->tenant->expected_units)->toBe(12);
});

it('defaults to Starter when the action is called without plan + expected_units (safe fallback)', function (): void {
    $action = app(RegisterTenantOperator::class);

    $result = $action->handle([
        'name' => 'Bare',
        'email' => 'bare@x.test',
        'property_name' => 'Bare Inn',
        'phone' => null,
        'password' => 'bare-9-9-9',
    ]);

    expect($result->tenant->plan_id)->toBe(SubscriptionPlan::Starter->value);
    expect($result->tenant->expected_units)->toBeNull();
});
