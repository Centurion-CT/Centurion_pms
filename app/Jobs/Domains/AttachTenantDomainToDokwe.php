<?php

declare(strict_types=1);

namespace App\Jobs\Domains;

use App\Models\Tenant;
use App\Services\Dokwe\DokweClient;
use App\Services\Dokwe\DokweException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Attaches `{slug}.keverna.com` to dokwe and triggers the SSL cert issue.
 *
 * Two-step: attachDomain() creates the row in dokwe; verify() asks dokwe
 * to run the DNS lookup + ACME challenge against the live resolvers.
 *
 * DNS itself is served by a single wildcard `*.keverna.com` at the
 * upstream provider (Namecheap today), so the record is already live
 * when this job runs — the retry loop below is mostly for slow ACME
 * issuance rather than propagation delay. verify() may still return
 * `verified=false` on the first attempt while ACME orders complete;
 * we throw to let Laravel's queue back-off (30s, 90s, 180s) ride it out.
 *
 * ACME permanent failures (sslStatus=failed with sslError populated) are
 * not retried — re-running won't help, an operator has to act. Logged at
 * error level so the admin panel surfaces them.
 *
 * Always chained after ProvisionTenantSubdomain in the queue.
 */
class AttachTenantDomainToDokwe implements NotTenantAware, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Generous backoff so DNS propagation has time. */
    public array $backoff = [30, 90, 180];

    public int $uniqueFor = 600;

    public function __construct(public int $tenantId)
    {
        // Route to the tenants supervisor — see config/horizon.php. Done in
        // the ctor so every dispatch site gets the right queue without
        // having to remember ->onQueue() at the call site.
        $this->onQueue('tenants');
    }

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function handle(DokweClient $client): void
    {
        // Defensive: in wildcard mode there is no per-tenant Dokwe attach
        // to do (the wildcard cert covers the whole zone). ProvisionTenant-
        // Subdomain already skips the dispatch, but a manually-dispatched
        // job or a stale queue entry from an older release could still land
        // here. Log and return so we don't fail on Dokwe verify.
        if (config('keverna.subdomain_provisioning.mode') === 'wildcard') {
            Log::info('AttachTenantDomainToDokwe: wildcard mode, no-op', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            Log::info('AttachTenantDomainToDokwe: tenant gone, skipping', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        $fqdn = $tenant->slug.config('keverna.tenant_domain_suffix');

        // ── Attach (idempotent) ─────────────────────────────────────────
        // DokweClient::attachDomain swallows the 409 "already configured"
        // case by listing and matching, so re-running this job after a
        // partial failure (id saved locally but verify retried) returns
        // the same id.
        $dokweId = $tenant->subdomain_dokwe_id;
        if ($dokweId === null) {
            try {
                $dokweId = $client->attachDomain($fqdn);
            } catch (DokweException $e) {
                $this->failTerminally($e, "attach {$fqdn}");

                return;
            }
            $tenant->forceFill(['subdomain_dokwe_id' => $dokweId])->save();
        }

        // ── Verify ──────────────────────────────────────────────────────
        try {
            $domain = $client->verify($dokweId);
        } catch (DokweException $e) {
            // 5xx → throw to retry; 4xx → terminal (bad request, wrong
            // project, etc. — retrying won't help).
            if ($e->isServerError()) {
                throw $e;
            }
            $this->failTerminally($e, "verify {$dokweId}");

            return;
        }

        if ($domain->isProvisioned()) {
            Log::info('AttachTenantDomainToDokwe: provisioned', [
                'tenant_id' => $this->tenantId,
                'fqdn' => $fqdn,
                'dokwe_id' => $dokweId,
            ]);

            return;
        }

        // sslStatus=failed with an sslError means ACME tripped on
        // something a retry won't fix (DNS still wrong, rate limit, etc.).
        if ($domain->isFailed()) {
            $this->failTerminally(
                new RuntimeException("SSL provisioning failed: {$domain->sslError}"),
                "verify {$dokweId}",
            );

            return;
        }

        // verified=false → DNS still propagating. Let Laravel retry.
        throw new RuntimeException(
            "Domain {$fqdn} not yet verified on dokwe — retrying",
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('AttachTenantDomainToDokwe failed after retries', [
            'tenant_id' => $this->tenantId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * Marks the job permanently failed (no more retries) with the given
     * cause. Used for client-side dokwe errors and ACME terminal states.
     */
    private function failTerminally(Throwable $e, string $context): void
    {
        Log::error("AttachTenantDomainToDokwe: terminal failure during {$context}", [
            'tenant_id' => $this->tenantId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
        $this->fail($e);
    }
}
