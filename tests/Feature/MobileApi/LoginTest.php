<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

/**
 * POST /api/mobile/v1/login on the CENTRAL domain — email +
 * password sign-in, with a picker step for identities that
 * belong to more than one tenant.
 */

beforeEach(function (): void {
    // Two tenants, one operator per tenant, both linked to the
    // same IdentityAccount (email = ada@example.com).
    $this->tenantA = Tenant::factory()->create(['slug' => 'chidi-hotel']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'lagos-resort']);

    // operators.email is globally unique (see migration 2026_05_22_215637);
    // the same central identity can still map to two operator rows via
    // IdentityOperatorLink — the per-operator email just has to be unique
    // for that row's own record. The mobile login flow only uses the
    // IdentityAccount email.
    $this->tenantA->makeCurrent();
    $this->opA = Operator::factory()->for($this->tenantA)->create([
        'email' => 'ada+chidi@example.com',
        'name' => 'Ada N.',
        'role' => OperatorRole::Frontdesk,
    ]);
    $this->tenantB->makeCurrent();
    $this->opB = Operator::factory()->for($this->tenantB)->create([
        'email' => 'ada+lagos@example.com',
        'name' => 'Ada N.',
        'role' => OperatorRole::Manager,
    ]);
    Tenant::forgetCurrent();

    $this->identity = IdentityAccount::query()->create([
        'email' => 'ada@example.com',
        'password' => Hash::make('correct-horse-battery-staple'),
        'name' => 'Ada N.',
    ]);
    IdentityOperatorLink::query()->create([
        'identity_account_id' => $this->identity->id,
        'tenant_id' => $this->tenantA->id,
        'operator_id' => $this->opA->id,
    ]);
    IdentityOperatorLink::query()->create([
        'identity_account_id' => $this->identity->id,
        'tenant_id' => $this->tenantB->id,
        'operator_id' => $this->opB->id,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

function centralLoginUrl(): string
{
    return 'http://'.config('keverna.central_domain').'/api/mobile/v1/login';
}

function baseBody(array $extra = []): array
{
    return array_merge([
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'platform' => 'ios',
        'device_name' => 'Ada\'s iPhone',
        'app_version' => '1.0.0+1',
    ], $extra);
}

it('returns 300 with a picker when the identity spans multiple tenants', function (): void {
    $response = $this->postJson(centralLoginUrl(), baseBody());

    $response->assertStatus(300)->assertJson(['status' => 'pick_tenant']);
    $tenants = collect($response->json('tenants'))->pluck('slug')->sort()->values()->all();
    expect($tenants)->toBe(['chidi-hotel', 'lagos-resort']);
});

it('signs in on a specific tenant when tenant_id is provided', function (): void {
    $response = $this->postJson(centralLoginUrl(), baseBody([
        'tenant_id' => $this->tenantA->id,
    ]));

    $response->assertOk();
    expect($response->json('token'))->toBeString();
    expect($response->json('tenant.slug'))->toBe('chidi-hotel');
    expect($response->json('operator.role'))->toBe('frontdesk');
    expect(MobileDevice::query()->withoutGlobalScope(\App\Multitenancy\TenantScope::class)->count())->toBe(1);
});

it('signs in immediately when the identity has only one linked tenant', function (): void {
    // Drop one of the links so the identity is single-tenant now.
    IdentityOperatorLink::query()->where('tenant_id', $this->tenantB->id)->delete();

    $response = $this->postJson(centralLoginUrl(), baseBody());

    $response->assertOk();
    expect($response->json('tenant.slug'))->toBe('chidi-hotel');
});

it('returns 401 for a wrong password (same shape as unknown email)', function (): void {
    $wrong = $this->postJson(centralLoginUrl(), baseBody(['password' => 'nope']));
    $wrong->assertStatus(401)->assertJson(['error' => 'invalid_credentials']);

    $unknown = $this->postJson(centralLoginUrl(), baseBody(['email' => 'nobody@x.test']));
    $unknown->assertStatus(401)->assertJson(['error' => 'invalid_credentials']);
});

it('returns 403 for an identity with zero linked tenants', function (): void {
    // Detach both.
    IdentityOperatorLink::query()->where('identity_account_id', $this->identity->id)->delete();

    $response = $this->postJson(centralLoginUrl(), baseBody());
    $response->assertStatus(403)->assertJson(['error' => 'no_tenants']);
});

it('refuses a tenant_id the identity is not linked to', function (): void {
    $foreign = Tenant::factory()->create(['slug' => 'foreign']);
    $response = $this->postJson(centralLoginUrl(), baseBody(['tenant_id' => $foreign->id]));
    $response->assertStatus(403)->assertJson(['error' => 'tenant_not_accessible']);
});

it('throttles after too many failed attempts', function (): void {
    // 6 failed attempts allowed (MAX_ATTEMPTS). 7th → 429.
    for ($i = 0; $i < 6; $i++) {
        $this->postJson(centralLoginUrl(), baseBody(['password' => 'wrong-'.$i]))
            ->assertStatus(401);
    }
    $this->postJson(centralLoginUrl(), baseBody(['password' => 'wrong-final']))
        ->assertStatus(429)
        ->assertJson(['error' => 'too_many_attempts']);
});

it('emitted token successfully authenticates against a tenant-scoped endpoint', function (): void {
    // Sign in on tenant A.
    $signIn = $this->postJson(centralLoginUrl(), baseBody([
        'tenant_id' => $this->tenantA->id,
    ]));
    $token = $signIn->json('token');

    // Hit /me on the tenant-A subdomain with the bearer token.
    $tenantSlug = $this->tenantA->slug;
    $meUrl = 'http://'.$tenantSlug.'.'.config('keverna.central_domain').'/api/mobile/v1/me';
    $meResponse = $this->getJson($meUrl, [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ]);

    $meResponse->assertOk();
    expect($meResponse->json('tenant.slug'))->toBe('chidi-hotel');
});
