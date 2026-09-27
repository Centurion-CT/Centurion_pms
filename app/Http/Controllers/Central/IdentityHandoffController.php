<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\SsoHandoff;
use App\Services\Identity\TenantSsoTokenSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Central SSO handoff (docs/unified-login.md §3, Phase 1c).
 *
 * Called when an authenticated identity opens a specific operator link
 * (via the account chooser, or directly for a single-tenant identity).
 * Emits a signed, single-use URL to `{tenant}.keverna.com/sso/consume`
 * and redirects the browser there.
 *
 * Security:
 *  - Verifies the link belongs to the currently-authenticated identity
 *    (defends against /handoff/{someoneElsesLinkId} tampering).
 *  - Stamps last_login_at on the link for chooser ordering.
 *  - Writes an sso_handoffs row so replay attempts on the same nonce
 *    fail on the consumer side.
 */
class IdentityHandoffController extends Controller
{
    public function __invoke(
        Request $request,
        IdentityOperatorLink $link,
        TenantSsoTokenSigner $signer,
    ): RedirectResponse {
        /** @var IdentityAccount $identity */
        $identity = $request->user('identity');

        abort_unless(
            (int) $link->identity_account_id === (int) $identity->id,
            404,
        );

        $link->load(['tenant', 'operator']);
        $link->forceFill(['last_login_at' => now()])->save();

        $token = $signer->issue($link);

        SsoHandoff::create([
            'nonce' => $token['nonce'],
            'identity_account_id' => $identity->id,
            'tenant_id' => $link->tenant_id,
            'operator_id' => $link->operator_id,
            'issued_at' => now(),
            'expires_at' => $token['expires_at'],
        ]);

        return redirect()->away($token['url']);
    }
}
