<?php

declare(strict_types=1);

use App\Actions\Auth\RegisterTenantOperator;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Livewire\Auth\Register;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\PostRegistrationLoginLink;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('renders the registration page at /register on the central domain', function (): void {
    $response = $this->get('http://keverna.test/register');

    $response->assertOk();
    $response->assertSeeLivewire(Register::class);
});

it('creates a Tenant + Operator atomically', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Amara Okafor')
        ->set('email', 'amara@maitama.test')
        ->set('property_name', 'Maitama Suites')
        ->set('phone', '+2348012345678')
        ->set('password', 'secret-pass-9')
        ->set('password_confirmation', 'secret-pass-9')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    expect(Tenant::query()->where('slug', 'maitama-suites')->exists())->toBeTrue();

    $tenant = Tenant::query()->where('slug', 'maitama-suites')->first();
    expect($tenant->status)->toBe(TenantStatus::Trial);
    expect($tenant->subscription_expires_at->isFuture())->toBeTrue();

    $operator = Operator::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('email', 'amara@maitama.test')
        ->first();

    expect($operator)->not->toBeNull();
    expect($operator->tenant_id)->toBe($tenant->id);
    expect($operator->name)->toBe('Amara Okafor');
});

it('hashes the operator password', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 't@a.test')
        ->set('property_name', 'Test Property')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'abcdef12')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    $operator = Operator::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('email', 't@a.test')
        ->first();

    expect($operator->password)->not->toBe('abcdef12');
    expect(strlen($operator->password))->toBeGreaterThan(40);
});

it('rejects duplicate operator emails', function (): void {
    $tenant = Tenant::factory()->create();
    Operator::factory()->for($tenant)->create(['email' => 'taken@x.test']);

    Livewire::test(Register::class)
        ->set('name', 'Other')
        ->set('email', 'taken@x.test')
        ->set('property_name', 'Some Place')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'abcdef12')
        ->set('license_code', issuedLicenseCode())
        ->call('register')
        ->assertHasErrors(['email' => 'unique']);
});

it('rejects weak passwords (under 8 chars, no number, etc.)', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 't@x.test')
        ->set('property_name', 'Test')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->set('license_code', issuedLicenseCode())
        ->call('register')
        ->assertHasErrors(['password']);
});

it('rejects mismatched password confirmation', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 't@x.test')
        ->set('property_name', 'Test')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'different9')
        ->set('license_code', issuedLicenseCode())
        ->call('register')
        ->assertHasErrors(['password' => 'confirmed']);
});

it('auto-generates a slug from the property name', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Amara')
        ->set('email', 'amara@one.test')
        ->set('property_name', 'Grand Palace Abuja!')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'abcdef12')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    expect(Tenant::query()->where('slug', 'grand-palace-abuja')->exists())->toBeTrue();
});

it('appends a numeric suffix when the slug is taken (PRD §6.1.1)', function (): void {
    Tenant::factory()->create(['slug' => 'grand-palace-abuja']);
    Tenant::factory()->create(['slug' => 'grand-palace-abuja-2']);

    Livewire::test(Register::class)
        ->set('name', 'Amara')
        ->set('email', 'amara@three.test')
        ->set('property_name', 'Grand Palace Abuja')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'abcdef12')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    expect(Tenant::query()->where('slug', 'grand-palace-abuja-3')->exists())->toBeTrue();
});

it('redirects to a post-registration login URL on the tenant subdomain', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Amara')
        ->set('email', 'amara@redirect.test')
        ->set('property_name', 'Redirect Test')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'abcdef12')
        ->set('license_code', issuedLicenseCode())
        ->call('register')
        ->assertRedirect();

    // Livewire's assertRedirect() only confirms a redirect occurred. We assert
    // shape separately by re-fetching the tenant + verifying the link helper
    // produces a URL on the tenant subdomain.
    $tenant = Tenant::query()->where('slug', 'redirect-test')->first();
    $operator = Operator::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->id)
        ->first();

    $url = app(PostRegistrationLoginLink::class)->generate($tenant, $operator);

    expect($url)->toContain($tenant->primaryHost());
    expect($url)->toContain('token=');
});

it('creates a trial Subscription alongside the tenant + operator (PRD §4.2)', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Trial User')
        ->set('email', 'trial@x.test')
        ->set('property_name', 'Trial Inn')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'abcdef12')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    $tenant = Tenant::query()->where('slug', 'trial-inn')->first();

    $sub = Subscription::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($sub)->not->toBeNull();
    expect($sub->plan)->toBe(SubscriptionPlan::Trial);
    expect($sub->status)->toBe(SubscriptionStatus::Trial);
    expect($sub->amount)->toBe(0);
    expect($sub->paid_at)->toBeNull();
    expect(abs($sub->expires_at->diffInDays(now())))->toBeBetween(13.5, 14.5);
});

it('creates a central IdentityAccount + operator link so /login works immediately after signup', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Amara')
        ->set('email', 'Amara@Central.Test')
        ->set('property_name', 'Central Login Inn')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'abcdef12')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    $identity = IdentityAccount::query()->where('email', 'amara@central.test')->first();
    expect($identity)->not->toBeNull();
    expect(Hash::check('abcdef12', (string) $identity->password))->toBeTrue();

    $tenant = Tenant::query()->where('slug', 'central-login-inn')->first();
    $operator = Operator::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->id)
        ->first();

    $link = IdentityOperatorLink::query()
        ->where('identity_account_id', $identity->id)
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($link)->not->toBeNull();
    expect($link->operator_id)->toBe($operator->id);
});

it('reuses an existing IdentityAccount (e.g. guest magic-link identity) when the operator registers later', function (): void {
    // Simulates the Phase-2 case: a guest identity was created without a
    // password via a booking magic link, and later that same person signs
    // up as an operator. We must not orphan them with a second row —
    // attach password + link to the existing identity.
    $existing = IdentityAccount::query()->create([
        'email' => 'reuse@identity.test',
        'name' => 'Old Guest Name',
        'password' => null,
    ]);

    Livewire::test(Register::class)
        ->set('name', 'New Operator Name')
        ->set('email', 'reuse@identity.test')
        ->set('property_name', 'Reuse Identity Inn')
        ->set('password', 'abcdef12')
        ->set('password_confirmation', 'abcdef12')
        ->set('license_code', issuedLicenseCode())
        ->call('register');

    $identities = IdentityAccount::query()->where('email', 'reuse@identity.test')->get();
    expect($identities)->toHaveCount(1);

    $identity = $identities->first();
    expect($identity->id)->toBe($existing->id);
    expect($identity->name)->toBe('New Operator Name');
    expect(Hash::check('abcdef12', (string) $identity->password))->toBeTrue();
});

it('rolls back tenant creation if operator save throws (atomic transaction)', function (): void {
    // Force operator creation to fail by pre-seeding a duplicate email at the
    // database layer. The unique constraint will fire during the inner save and
    // the surrounding DB::transaction must roll back the just-created tenant.
    $existingTenant = Tenant::factory()->create();
    Operator::factory()->for($existingTenant)->create(['email' => 'will@conflict.test']);

    $tenantCountBefore = Tenant::count();

    try {
        // Skip the unique validation path — call the action directly so we
        // exercise the DB-level uniqueness rollback, not the Livewire validator.
        app(RegisterTenantOperator::class)->handle([
            'name' => 'X',
            'email' => 'will@conflict.test',
            'property_name' => 'New Property',
            'phone' => null,
            'password' => 'abcdef12',
        ]);
        $this->fail('Expected QueryException for duplicate email.');
    } catch (QueryException) {
        // Expected.
    }

    expect(Tenant::count())->toBe($tenantCountBefore);
    expect(Tenant::query()->where('slug', 'new-property')->exists())->toBeFalse();
});
