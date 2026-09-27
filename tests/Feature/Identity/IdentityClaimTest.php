<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Models\Customer;
use App\Models\GuestClaimToken;
use App\Models\IdentityAccount;
use App\Models\IdentityGuestLink;
use App\Models\Tenant;
use App\Services\Identity\GuestClaimTokenSigner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/**
 * Issue a real signed URL AND persist the matching guest_claim_token row —
 * the controller demands both a valid signature and a live nonce.
 *
 * @return array{url: string, nonce: string}
 */
function issueValidClaim(string $email, ?Tenant $originTenant = null): array
{
    $signer = app(GuestClaimTokenSigner::class);
    $token = $signer->issue($email);

    GuestClaimToken::create([
        'nonce' => $token['nonce'],
        'email' => IdentityAccount::normaliseEmail($email),
        'origin_tenant_id' => $originTenant?->id,
        'issued_at' => now(),
        'expires_at' => $token['expires_at'],
    ]);

    return ['url' => $token['url'], 'nonce' => $token['nonce']];
}

it('creates a fresh identity, merges customers across tenants, and logs the guest in', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'claim-a']);
    $tenantB = Tenant::factory()->create(['slug' => 'claim-b']);

    $tenantA->makeCurrent();
    Customer::create(['name' => 'Guest', 'email' => 'newbie@x.test', 'lifecycle' => CustomerLifecycle::Prospect]);
    $tenantB->makeCurrent();
    Customer::create(['name' => 'Guest', 'email' => 'newbie@x.test', 'lifecycle' => CustomerLifecycle::Prospect]);
    Tenant::forgetCurrent();

    $claim = issueValidClaim('newbie@x.test', $tenantA);

    $response = $this->get($claim['url']);

    $response->assertRedirect(route('central.reservations'));

    $identity = IdentityAccount::query()->where('email', 'newbie@x.test')->firstOrFail();
    expect($identity->email_verified_at)->not->toBeNull();
    expect($identity->guestLinks()->count())->toBe(2);
    expect(Auth::guard('identity')->id())->toBe($identity->id);

    // Nonce marked consumed and resolved to the identity.
    $token = GuestClaimToken::query()->where('nonce', $claim['nonce'])->firstOrFail();
    expect($token->consumed_at)->not->toBeNull();
    expect($token->resolved_identity_account_id)->toBe($identity->id);
});

it('reuses an existing identity when the email is already registered', function (): void {
    IdentityAccount::create([
        'email' => 'existing@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Existing',
    ]);

    $tenant = Tenant::factory()->create(['slug' => 'claim-existing']);
    $tenant->makeCurrent();
    Customer::create(['name' => 'Existing', 'email' => 'existing@x.test', 'lifecycle' => CustomerLifecycle::Prospect]);
    Tenant::forgetCurrent();

    $claim = issueValidClaim('existing@x.test');

    $this->get($claim['url'])->assertRedirect(route('central.reservations'));

    expect(IdentityAccount::count())->toBe(1);
    $identity = IdentityAccount::first();
    expect($identity->guestLinks()->count())->toBe(1);
});

it('rejects a replayed claim URL', function (): void {
    $claim = issueValidClaim('replay@x.test');

    $this->get($claim['url'])->assertRedirect();
    Auth::guard('identity')->logout();

    $this->get($claim['url'])->assertStatus(401);
});

it('rejects an expired claim URL', function (): void {
    $signer = app(GuestClaimTokenSigner::class);
    $token = $signer->issue('expired@x.test', ttlSeconds: -10);
    GuestClaimToken::create([
        'nonce' => $token['nonce'],
        'email' => 'expired@x.test',
        'issued_at' => now(),
        'expires_at' => $token['expires_at'],
    ]);

    $this->get($token['url'])->assertStatus(401);
});

it('rejects a tampered claim URL', function (): void {
    $claim = issueValidClaim('tampered@x.test');
    $tampered = str_replace('tampered', 'attacker', $claim['url']);

    $this->get($tampered)->assertStatus(401);
});

it('rejects a URL for an unknown nonce (signature ok, no ledger row)', function (): void {
    // Sign a URL but do NOT create the ledger row. Signature verifies,
    // step 2 rejects.
    $signer = app(GuestClaimTokenSigner::class);
    $token = $signer->issue('nonledger@x.test');

    $this->get($token['url'])->assertStatus(401);
});
