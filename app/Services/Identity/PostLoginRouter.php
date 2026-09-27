<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\IdentityAccount;

/**
 * Resolves where a freshly-authenticated IdentityAccount should be sent.
 * Implements the routing table in docs/unified-login.md §3.
 *
 * Deterministic + side-effect-free — safe to call from anywhere, but
 * only writes to the URL of the response via the calling controller /
 * Livewire component.
 *
 * Activation filter: operator links that point at an Operator with
 * `activated_at IS NULL` are ignored. An identity whose only links
 * are pending-activation invites resolves to `invite_pending`, which
 * the central Login surfaces as "check your invite email" — the
 * invitee is never handed off to a tenant they can't actually
 * sign into.
 *
 * Phase 2 will extend Destination with the guest link count check.
 * Today "guest-only" is a stub: no identity has guest links yet.
 */
final readonly class PostLoginRouter
{
    /**
     * @return array{kind: 'handoff'|'reservations'|'choose'|'invite_pending', link_id?: int}
     */
    public function resolve(IdentityAccount $identity): array
    {
        // preferred_link_id shortcut: user previously ticked "take me
        // straight here next time" on the chooser. Honour it if the
        // link still belongs to this identity AND its operator is
        // activated (an un-activated preferred link would loop them
        // through a failing handoff).
        if ($identity->preferred_link_id !== null) {
            $preferred = $this->activatedOperatorLinks($identity)
                ->whereKey($identity->preferred_link_id)
                ->first();

            if ($preferred !== null) {
                return ['kind' => 'handoff', 'link_id' => (int) $preferred->id];
            }

            // Stale preference (link was removed or is pending
            // activation). Fall through to the normal routing rules.
        }

        $activatedLinks = $this->activatedOperatorLinks($identity)->count();
        $totalLinks = $identity->operatorLinks()->count();
        $guestLinkCount = $this->guestLinkCount($identity);

        // No activated links, no guest links, but at least one
        // pending-activation operator link → invite_pending. Tells the
        // central Login to reject with a "check your invite email"
        // banner instead of handing off to a tenant that will refuse
        // the sign-in.
        if ($activatedLinks === 0 && $guestLinkCount === 0 && $totalLinks > 0) {
            return ['kind' => 'invite_pending'];
        }

        // exactly 1 activated operator, 0 guests → handoff to that tenant
        if ($activatedLinks === 1 && $guestLinkCount === 0) {
            $link = $this->activatedOperatorLinks($identity)->first();

            return ['kind' => 'handoff', 'link_id' => (int) $link->id];
        }

        // 0 activated operators, ≥1 guest → reservations dashboard
        if ($activatedLinks === 0 && $guestLinkCount >= 1) {
            return ['kind' => 'reservations'];
        }

        // Everything else (multi-tenant operator, dual-role, or 0/0):
        // send them to the chooser. The chooser also handles the empty
        // case with a "no linked accounts yet" state.
        return ['kind' => 'choose'];
    }

    /**
     * Operator links whose Operator has been activated AND is not
     * suspended / revoked. Uses `whereHas` on the operator relation
     * — which is declared with `withoutGlobalScope(TenantScope::class)`
     * on IdentityOperatorLink, so this works in central context
     * where no current tenant is set.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\IdentityOperatorLink, \App\Models\IdentityAccount>
     */
    private function activatedOperatorLinks(IdentityAccount $identity)
    {
        return $identity->operatorLinks()
            ->whereHas('operator', fn ($q) => $q
                ->whereNotNull('activated_at')
                ->whereNull('suspended_at')
                ->whereNull('revoked_at'));
    }

    /**
     * Guest link count (Phase 2). Reads identity_guest_links directly —
     * cheap `count()` query rather than loading the relation, because
     * the router only branches on presence, not on the rows themselves.
     */
    private function guestLinkCount(IdentityAccount $identity): int
    {
        return $identity->guestLinks()->count();
    }
}
