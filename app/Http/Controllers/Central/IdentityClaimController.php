<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\GuestClaimToken;
use App\Models\IdentityAccount;
use App\Services\Identity\CrossTenantCustomerMerger;
use App\Services\Identity\GuestClaimTokenSigner;
use App\Services\Identity\SsoTokenException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Central magic-link claim endpoint (docs/unified-login.md §3, Phase 2).
 *
 * Verifies a signed URL, atomically marks the nonce consumed, then:
 *  1. Upserts an IdentityAccount for the verified email (marks it
 *     email_verified since clicking the link proves ownership).
 *  2. Runs CrossTenantCustomerMerger to link every matching Customer
 *     row across every tenant into identity_guest_links.
 *  3. Logs the identity into the `identity` guard and bounces them to
 *     /reservations, which now has real guest links to render.
 *
 * Idempotent on re-click of the same URL:
 *  - Same nonce → single-use; second consume returns 401.
 *  - Different valid link, same email → identity already exists, merger
 *    is idempotent, just logs the user back in.
 */
class IdentityClaimController extends Controller
{
    public function __invoke(
        Request $request,
        GuestClaimTokenSigner $signer,
        CrossTenantCustomerMerger $merger,
    ): RedirectResponse {
        // Step 1 — signature + expiry.
        try {
            $payload = $signer->verify($request);
        } catch (SsoTokenException $e) {
            abort(401, "Claim rejected: {$e->kind}");
        }

        // Step 2 — nonce lookup + atomic mark-consumed.
        $token = GuestClaimToken::query()->where('nonce', $payload['nonce'])->first();
        if ($token === null || $token->isConsumed() || $token->isExpired()) {
            abort(401, 'Claim rejected: nonce is unknown, spent, or expired.');
        }

        $affected = GuestClaimToken::query()
            ->where('id', $token->id)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'consumed_ip' => $request->ip(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            abort(401, 'Claim rejected: nonce was consumed by a concurrent request.');
        }

        // Step 3 — upsert the identity. Email is verified by the click.
        $email = IdentityAccount::normaliseEmail($payload['email']);
        $identity = IdentityAccount::query()->where('email', $email)->first();
        if ($identity === null) {
            $identity = new IdentityAccount;
            $identity->email = $email;
            // No password — guest is magic-link-only in Phase 2.
            $identity->password = null;
            $identity->name = $this->guessNameForEmail($email);
            $identity->email_verified_at = now();
            $identity->save();
        } elseif ($identity->email_verified_at === null) {
            $identity->forceFill(['email_verified_at' => now()])->save();
        }

        // Step 4 — cross-tenant merge.
        $merger->mergeFor($identity);

        // Step 5 — audit link back on the token row.
        GuestClaimToken::query()
            ->where('id', $token->id)
            ->update(['resolved_identity_account_id' => $identity->id, 'updated_at' => now()]);

        // Step 6 — log in via identity guard.
        Auth::guard('identity')->login($identity);
        $request->session()->regenerate();
        $identity->forceFill(['last_login_at' => now()])->save();

        // Straight to the guest dashboard — the user came here because
        // they want to see their reservations, not choose an account.
        return redirect()->route('central.reservations');
    }

    /**
     * Best-effort name for a fresh identity — email-local as a title-cased
     * placeholder. The guest can rename themselves later once we ship a
     * profile screen; for now this beats an empty string.
     */
    private function guessNameForEmail(string $email): string
    {
        $local = str_contains($email, '@') ? substr($email, 0, strpos($email, '@')) : $email;
        $local = str_replace(['.', '_', '-', '+'], ' ', $local);

        return ucwords($local);
    }
}
