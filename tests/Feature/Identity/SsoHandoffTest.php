<?php

declare(strict_types=1);

use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\SsoHandoff;
use App\Models\Tenant;
use App\Services\Identity\TenantSsoTokenSigner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/**
 * Set up: one identity, one operator link, one tenant, one operator.
 * Returns everything the SSO test needs to drive both hops.
 *
 * @return array{IdentityAccount, IdentityOperatorLink, Tenant, Operator}
 */
function makeSsoScenario(string $slug = 'sso'): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug]);
    $tenant->makeCurrent();
    $operator = Operator::factory()->for($tenant)->create();
    Tenant::forgetCurrent();

    $identity = IdentityAccount::create([
        'email' => "sso-{$slug}@x.test",
        'password' => Hash::make('pw'),
        'name' => 'SSO Tester',
    ]);

    $link = IdentityOperatorLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenant->id,
        'operator_id' => $operator->id,
    ]);

    return [$identity, $link, $tenant, $operator];
}

/**
 * Perform the handoff redirect and return the target URL that the
 * browser would follow. Fails the test if the response isn't a redirect
 * to the tenant subdomain.
 */
function driveHandoff(object $test, IdentityAccount $identity, IdentityOperatorLink $link): string
{
    $response = $test->actingAs($identity, 'identity')
        ->get('http://keverna.test/handoff/'.$link->id);

    $response->assertRedirect();
    /** @var string $location */
    $location = $response->headers->get('Location');
    expect($location)->toContain('/sso/consume?');

    return $location;
}

it('completes the full handoff → consume flow and logs the operator in on the tenant guard', function (): void {
    [$identity, $link, $tenant, $operator] = makeSsoScenario('happy');

    $location = driveHandoff($this, $identity, $link);

    // Simulate the browser following the redirect to the tenant subdomain.
    $response = $this->get($location);

    // Consume redirects to the onboarding router which then decides
    // exactly where — we just assert we bounced there.
    $response->assertRedirect(route('tenant.onboarding'));

    // Operator is now authenticated on the tenant guard.
    $this->assertAuthenticatedAs($operator, 'web');

    // Nonce row was marked consumed.
    $handoff = SsoHandoff::query()->orderByDesc('id')->first();
    expect($handoff->consumed_at)->not->toBeNull();
    expect($handoff->consumed_ip)->not->toBeNull();
});

it('rejects a replayed consume URL', function (): void {
    [$identity, $link] = makeSsoScenario('replay');
    $location = driveHandoff($this, $identity, $link);

    $this->get($location)->assertRedirect();
    Auth::guard('web')->logout();

    // Same URL, second try.
    $this->get($location)->assertStatus(401);
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('rejects a URL whose signature has been tampered with', function (): void {
    [$identity, $link] = makeSsoScenario('tampered');
    $location = driveHandoff($this, $identity, $link);

    // Flip the operator id in the query string — signature was computed
    // over the original tuple so verification fails.
    $tampered = preg_replace('/operator=\d+/', 'operator=999', $location);

    $this->get($tampered)->assertStatus(401);
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('rejects a URL signed for tenant A when submitted to tenant B (host binding)', function (): void {
    [$identity, $link] = makeSsoScenario('tenant-a');
    // Second tenant unused-in-URL but exists so the subdomain resolves.
    Tenant::factory()->create(['slug' => 'tenant-b']);

    $location = driveHandoff($this, $identity, $link);

    // Rewrite the URL to point at tenant-b's subdomain. The signature was
    // computed with tenant-a's host, so signature verification fails.
    $redirected = str_replace('tenant-a.keverna.test', 'tenant-b.keverna.test', $location);

    $this->get($redirected)->assertStatus(401);
});

it('rejects a token with an expiry in the past', function (): void {
    [$identity, $link] = makeSsoScenario('exp');
    $signer = app(TenantSsoTokenSigner::class);

    // Issue with a negative TTL so `expires` is already in the past.
    $link->load(['tenant', 'operator']);
    $token = $signer->issue($link, ttlSeconds: -10);

    // Also record the nonce, otherwise the token would fail at step 2
    // and we wouldn't be testing the expiry path.
    SsoHandoff::create([
        'nonce' => $token['nonce'],
        'identity_account_id' => $identity->id,
        'tenant_id' => $link->tenant_id,
        'operator_id' => $link->operator_id,
        'issued_at' => now(),
        'expires_at' => $token['expires_at'],
    ]);

    $this->get($token['url'])->assertStatus(401);
});

it('rejects a valid token when the identity → operator link has been revoked', function (): void {
    [$identity, $link] = makeSsoScenario('revoke');
    $location = driveHandoff($this, $identity, $link);

    // Ops revoked the link between issuance and consume.
    $link->delete();

    $this->get($location)->assertStatus(403);
});

it('rejects a token whose SsoHandoff row has been manually retargeted to a different tenant', function (): void {
    [$identity, $link] = makeSsoScenario('retarget-a');
    $otherTenant = Tenant::factory()->create(['slug' => 'retarget-b']);

    $location = driveHandoff($this, $identity, $link);

    // Simulate a corrupted / tampered SsoHandoff row — the URL host still
    // says retarget-a (signature holds), but the DB row now claims the
    // handoff was for retarget-b. Step 3 in the consumer catches this.
    SsoHandoff::query()->update(['tenant_id' => $otherTenant->id]);

    $this->get($location)->assertStatus(403);
});
