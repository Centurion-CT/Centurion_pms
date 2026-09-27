<?php

declare(strict_types=1);

namespace App\Jobs\Domains;

use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Marks a tenant's `{slug}.keverna.com` subdomain as live and asks Dokwe
 * to attach it.
 *
 * DNS is served by a single wildcard record (`*.keverna.com`) that points
 * at Dokwe's ingress, so we do NOT create a per-tenant DNS record here —
 * that was the pre-wildcard architecture (Cloudflare CNAME per tenant),
 * which does not scale to 1M subdomains and coupled us to Cloudflare's
 * API rate limits. See docs/subdomain-dns.md for the wildcard setup and
 * TLS cert provisioning at Dokwe.
 *
 * The job is dispatched on TenantOnboarded + SubscriptionActivated; both
 * events can fire for the same tenant, so it is idempotent on two layers:
 *   1. ShouldBeUnique by tenant_id while pending or running.
 *   2. `subdomain_provisioned_at` is only stamped once; re-runs no-op.
 *
 * On success `AttachTenantDomainToDokwe` is dispatched so Dokwe can start
 * routing traffic. That step is a separate job with its own retry policy
 * because a slow ACME issuance shouldn't unwind the provisioning stamp.
 *
 * In wildcard mode (keverna.subdomain_provisioning.mode = 'wildcard' —
 * the current production setup) the Dokwe attach step is skipped: the
 * subdomain is already routable via `*.keverna.com` + wildcard cert the
 * moment the row exists, so there's no external work to defer.
 */
class ProvisionTenantSubdomain implements NotTenantAware, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var int max attempts */
    public int $tries = 3;

    /** @var array<int,int> per-attempt backoff in seconds */
    public array $backoff = [10, 30, 60];

    /** Only one job per tenant at a time (covers near-simultaneous events). */
    public int $uniqueFor = 300;

    public function __construct(public int $tenantId) {}

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            Log::info('ProvisionTenantSubdomain: tenant gone, skipping', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        if ($tenant->subdomain_provisioned_at !== null) {
            // Already done. Earlier attempt succeeded or another listener
            // beat us to it. AttachTenantDomainToDokwe is dispatched by
            // that earlier run; it's ShouldBeUnique on tenant_id, so a
            // re-dispatch here would be deduped anyway.
            return;
        }

        $tenant->forceFill(['subdomain_provisioned_at' => Carbon::now()])->save();

        // Wildcard mode: the *.keverna.com wildcard cert + DNS already cover
        // the tenant subdomain, so there's no per-tenant Dokwe attach to do.
        // Skip the chained job entirely — running it would 404 on Dokwe's
        // verify step (no per-subdomain CNAME exists) and just burn retries.
        if (config('keverna.subdomain_provisioning.mode') === 'wildcard') {
            return;
        }

        AttachTenantDomainToDokwe::dispatch($this->tenantId);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProvisionTenantSubdomain failed after retries', [
            'tenant_id' => $this->tenantId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
