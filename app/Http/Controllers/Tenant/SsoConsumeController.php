<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\SsoHandoff;
use App\Models\Tenant;
use App\Services\Identity\SsoTokenException;
use App\Services\Identity\TenantSsoTokenSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Consumes a signed SSO handoff URL and logs the linked Operator into
 * the tenant `web` guard (docs/unified-login.md §3, Phase 1c).
 *
 * Runs inside the tenant middleware group, so Tenant::current() is
 * the subdomain-resolved tenant. Every check is defensive because the
 * URL was constructed by another process and delivered via redirect.
 *
 * Chain of defense (in order — each is independently sufficient to
 * refuse a bad request):
 *   1. HMAC signature valid + not expired (TenantSsoTokenSigner::verify)
 *   2. SsoHandoff nonce row exists, not already consumed, not expired
 *   3. Handoff was issued for the tenant we're currently on
 *   4. Operator row still exists and belongs to the current tenant
 *   5. Identity → operator link still exists (guards against revocation
 *      between issue and consume)
 *
 * Race: two concurrent consumes on the same nonce are handled by the
 * conditional UPDATE at step 2 — only one wins, the other sees
 * affectedRows === 0 and 401s.
 */
class SsoConsumeController extends Controller
{
    public function __invoke(Request $request, TenantSsoTokenSigner $signer): RedirectResponse
    {
        // Step 1 — signature + expiry.
        try {
            $payload = $signer->verify($request);
        } catch (SsoTokenException $e) {
            abort(401, "SSO rejected: {$e->kind}");
        }

        // Step 2 — nonce lookup + atomic mark-consumed.
        $handoff = SsoHandoff::query()
            ->where('nonce', $payload['nonce'])
            ->first();

        if ($handoff === null || $handoff->isConsumed() || $handoff->isExpired()) {
            abort(401, 'SSO rejected: nonce is unknown, spent, or expired.');
        }

        // Conditional UPDATE — the WHERE clause guarantees exactly one
        // concurrent consumer succeeds. If a racing request marked it
        // consumed a microsecond ago, affected rows will be zero here.
        $affected = SsoHandoff::query()
            ->where('id', $handoff->id)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'consumed_ip' => $request->ip(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            abort(401, 'SSO rejected: nonce was consumed by a concurrent request.');
        }

        // Step 3 — this handoff must be aimed at us.
        /** @var Tenant $currentTenant */
        $currentTenant = Tenant::current();
        if ((int) $handoff->tenant_id !== (int) $currentTenant->getKey()) {
            abort(403, 'SSO rejected: handoff was issued for a different tenant.');
        }

        // Step 4 — Operator must still exist under this tenant. Bypassing
        // BelongsToTenant here defensively; we then assert the tenant_id
        // ourselves so we catch stale operator ids that got renumbered
        // (extremely unlikely, but the assertion is cheap).
        $operator = Operator::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $handoff->operator_id)
            ->first();

        if ($operator === null || (int) $operator->tenant_id !== (int) $currentTenant->getKey()) {
            abort(403, 'SSO rejected: operator does not belong to this tenant.');
        }

        // Step 5 — the identity → operator link must still be valid.
        // If ops revoked the link between issue and consume, refuse.
        $linkStillValid = IdentityOperatorLink::query()
            ->where('identity_account_id', $handoff->identity_account_id)
            ->where('tenant_id', $handoff->tenant_id)
            ->where('operator_id', $handoff->operator_id)
            ->exists();

        if (! $linkStillValid) {
            abort(403, 'SSO rejected: identity is no longer linked to this operator.');
        }

        // Step 6 — the operator itself must be signable. Defense in
        // depth: PostLoginRouter should already have filtered these
        // out before issuing the handoff, but a mid-flight
        // suspend/revoke between issue and consume must never let
        // the operator through.
        if (! $operator->canSignIn()) {
            abort(403, 'SSO rejected: operator is not currently allowed to sign in.');
        }

        // All green — log the operator in on the tenant `web` guard.
        // We do NOT touch the central `identity` guard here; that
        // session lives on the central domain and stays intact.
        Auth::guard('web')->login($operator);
        $request->session()->regenerate();
        $operator->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('tenant.onboarding');
    }
}
