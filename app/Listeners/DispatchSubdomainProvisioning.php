<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SubscriptionActivated;
use App\Events\TenantOnboarded;
use App\Jobs\Domains\ProvisionTenantSubdomain;

/**
 * Both TenantOnboarded (wizard finished) and SubscriptionActivated (first
 * paid charge) should result in a live `{slug}.keverna.com` subdomain
 * (PRD §6.6.1). This listener fans both events into the same idempotent job.
 *
 * Registered explicitly in AppServiceProvider::boot() since it handles two
 * unrelated event types — Laravel's auto-discovery only binds one event
 * per listener class.
 */
class DispatchSubdomainProvisioning
{
    public function handleTenantOnboarded(TenantOnboarded $event): void
    {
        ProvisionTenantSubdomain::dispatch($event->tenant->id);
    }

    public function handleSubscriptionActivated(SubscriptionActivated $event): void
    {
        ProvisionTenantSubdomain::dispatch($event->subscription->tenant_id);
    }
}
