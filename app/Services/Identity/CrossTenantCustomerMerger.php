<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Customer;
use App\Models\IdentityAccount;
use App\Models\IdentityGuestLink;

/**
 * Runs the cross-tenant Customer merge for a verified identity email
 * (docs/unified-login.md §3, Phase 2 "Backfill / Guests, lazy").
 *
 * Behaviour:
 *  - Scans every Customer row across every tenant whose email matches
 *    the identity's (case-insensitive), bypassing BelongsToTenant.
 *  - For each matched Customer, ensures an IdentityGuestLink exists for
 *    (identity, tenant, customer). Idempotent — safe to re-run.
 *  - Returns the count of new links created for logging.
 *
 * Called by:
 *  - IdentityClaimController on first claim
 *  - Central Login::authenticate on every login (cheap, keeps future
 *    bookings on the same email in sync without waiting for the guest
 *    to reclaim)
 */
final class CrossTenantCustomerMerger
{
    /**
     * Merge every Customer row whose email matches $identity->email
     * into identity_guest_links, keyed to the given identity.
     */
    public function mergeFor(IdentityAccount $identity): int
    {
        $email = $identity->email; // already normalised

        // Bypass BelongsToTenant — we intentionally read every tenant's
        // Customer rows here. The match is on the normalised email; case
        // handled by the SQL LOWER() so we don't miss `Alice@x.test`
        // stored on tenant A when the identity is 'alice@x.test'.
        $customers = Customer::query()
            ->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->get(['id', 'tenant_id']);

        $created = 0;

        foreach ($customers as $customer) {
            $exists = IdentityGuestLink::query()
                ->where('tenant_id', $customer->tenant_id)
                ->where('customer_id', $customer->id)
                ->exists();

            if ($exists) {
                continue;
            }

            IdentityGuestLink::create([
                'identity_account_id' => $identity->id,
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'claimed_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }
}
