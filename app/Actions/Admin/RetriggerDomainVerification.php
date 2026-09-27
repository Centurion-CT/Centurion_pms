<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Models\Admin;
use App\Models\Tenant;
use RuntimeException;

/**
 * Re-queue {@see VerifyCustomDomain} for a tenant whose custom domain
 * never verified or fell into Error (PRD §6.6.2).
 *
 * The job is `ShouldBeUnique` (15-min lock keyed by tenant id), so
 * back-to-back admin clicks collapse to a single dispatch.
 */
class RetriggerDomainVerification
{
    public function handle(Tenant $tenant, Admin $admin): void
    {
        if ($tenant->custom_domain === null || $tenant->custom_domain === '') {
            throw new RuntimeException("Tenant {$tenant->slug} has no custom domain attached.");
        }

        // Flipping from Active back to Pending lets the operator see the
        // re-verification spin without our re-queue being a silent no-op
        // (the job itself short-circuits when status === Active).
        if ($tenant->custom_domain_status === CustomDomainStatus::Active) {
            $tenant->forceFill(['custom_domain_status' => CustomDomainStatus::Pending])->save();
        }

        VerifyCustomDomain::dispatch($tenant->id);

        activity('admin')
            ->causedBy($admin)
            ->performedOn($tenant)
            ->event('domain_reverify_queued')
            ->withProperties(['domain' => $tenant->custom_domain])
            ->log("Admin {$admin->name} re-queued domain verification for {$tenant->custom_domain}.");
    }
}
