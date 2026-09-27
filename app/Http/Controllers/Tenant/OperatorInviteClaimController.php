<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Models\Operator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Consumes an operator-invite magic link.
 *
 * URL shape:
 *   GET /operator-invite/claim/{operator}/{token}?expires=&signature=
 *
 * The signature is Laravel's built-in `signed` middleware (HMAC over
 * scheme + host + path + query, keyed by APP_KEY). This controller
 * only needs to verify the nonce token and then hand off to the
 * activation page — the invitee is NOT logged in here because the
 * account isn't activated yet.
 *
 * On success: stash the operator id in the session under a scoped
 * key + log out any existing `web` session so the activation page
 * renders in a clean guest layout, then redirect to /invite/activate.
 * The token itself is NOT nulled here — it stays valid until the
 * invitee actually saves a password on the activation page. That
 * keeps the link idempotent: bouncing between browsers, closing the
 * tab, or navigating away all leave the invitee with a working link
 * they can re-open until they finish activation (or the 24h expiry
 * hits). Once activated, the ActivateInvite component clears the
 * token, so subsequent clicks are correctly rejected.
 *
 * If the operator is already activated (invitee already ran through
 * the flow once, or a manager just activated the account manually),
 * we skip the activation page entirely and bounce to /login with an
 * explanatory banner.
 *
 * On failure (expired, invalid signature, tampered token): back to
 * /login with an error banner. The invitee must ask their manager to
 * resend.
 */
class OperatorInviteClaimController
{
    /** Session key that gates access to /invite/activate. */
    public const SESSION_KEY = 'invite.pending_operator_id';

    public function __invoke(Request $request, int $operator, string $token): RedirectResponse
    {
        // The `signed` middleware runs first, so a bad/tampered URL
        // never reaches us. Belt-and-braces guard in case someone
        // wires the route without the middleware later.
        if (! $request->hasValidSignature()) {
            return redirect()->route('tenant.login')
                ->with('error', 'This invite link is invalid or has been tampered with.');
        }

        /** @var Operator|null $op */
        $op = Operator::query()->find($operator);
        if ($op === null || $op->invite_token_hash === null) {
            return redirect()->route('tenant.login')
                ->with('error', 'This invite link is no longer valid. Ask your manager to send a fresh invite.');
        }

        // Already-activated: link was used, invitee (or a manager)
        // already picked a password. Point them at /login rather than
        // the activate page — no password to set anymore.
        if ($op->activated_at !== null) {
            return redirect()->route('tenant.login')
                ->with('status', 'Your account is already activated — sign in with the password you picked.');
        }

        if ($op->invite_expires_at !== null && $op->invite_expires_at->isPast()) {
            return redirect()->route('tenant.login')
                ->with('error', 'This invite link has expired. Ask your manager to send a fresh invite.');
        }

        if (! Hash::check($token, $op->invite_token_hash)) {
            return redirect()->route('tenant.login')
                ->with('error', 'This invite link is invalid.');
        }

        // Drop any existing tenant login session before showing the
        // activation page. An invitee opening the link in a browser
        // that already holds a `web` session (another operator, or a
        // stale cookie from cross-subdomain sharing) would otherwise
        // see the dashboard sidebar wrapping the activation form —
        // the layout renders its @auth('web') block whenever the
        // guard is authenticated. Logout + regenerate keeps the
        // guest layout in play.
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();
        }

        // Stash the operator id in the session so /invite/activate
        // knows who is activating. The token itself is NOT nulled
        // here — it stays valid until the invitee actually saves a
        // password, so a mid-flow browser reload / device switch
        // still works. ActivateInvite clears it on save.
        $request->session()->put(self::SESSION_KEY, $op->id);

        return redirect()->route('tenant.invite.activate');
    }
}
