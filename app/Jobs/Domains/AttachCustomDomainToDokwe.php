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
 * Attaches an operator-supplied `custom_domain` to dokwe and triggers SSL
 * cert issuance. Only dispatched AFTER VerifyCustomDomain has stamped
 * `domain_verified_at` — by then we know the CNAME points at our edge
 * and the operator's TXT proof is in place, so dokwe's own DNS check
 * will succeed on the first verify() call rather than racing propagation.
 *
 * Mirrors AttachTenantDomainToDokwe but reads `custom_domain` /
 * `custom_domain_dokwe_id` instead of the slug/subdomain pair. Kept
 * separate because the trigger, lifecycle (renames + detaches), and
 * retry semantics are different — a custom domain can be changed by the
 * operator, a subdomain can't.
 */
class AttachCustomDomainToDokwe implements NotTenantAware, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Shorter than the subdomain job's backoff — DNS is already verified. */
    public array $backoff = [15, 60, 120];

    public int $uniqueFor = 600;

    public function __construct(public int $tenantId)
    {
        $this->onQueue('tenants');
    }

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function handle(DokweClient $client): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            Log::info('AttachCustomDomainToDokwe: tenant gone, skipping', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        $domain = $tenant->custom_domain;
        if ($domain === null || $domain === '') {
            // Custom domain was cleared after dispatch — nothing to attach.
            Log::info('AttachCustomDomainToDokwe: no custom_domain set, skipping', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        // ── Attach (idempotent) ─────────────────────────────────────────
        $dokweId = $tenant->custom_domain_dokwe_id;
        if ($dokweId === null) {
            try {
                $dokweId = $client->attachDomain($domain);
            } catch (DokweException $e) {
                $this->failTerminally($e, "attach {$domain}");

                return;
            }
            $tenant->forceFill(['custom_domain_dokwe_id' => $dokweId])->save();
        }

        // ── Verify ──────────────────────────────────────────────────────
        try {
            $row = $client->verify($dokweId);
        } catch (DokweException $e) {
            if ($e->isServerError()) {
                throw $e;
            }
            $this->failTerminally($e, "verify {$dokweId}");

            return;
        }

        if ($row->isProvisioned()) {
            Log::info('AttachCustomDomainToDokwe: provisioned', [
                'tenant_id' => $this->tenantId,
                'domain' => $domain,
                'dokwe_id' => $dokweId,
            ]);

            return;
        }

        if ($row->isFailed()) {
            $this->failTerminally(
                new RuntimeException("SSL provisioning failed: {$row->sslError}"),
                "verify {$dokweId}",
            );

            return;
        }

        // Unexpected: VerifyCustomDomain ran before us so DNS should be
        // correct. Throw to retry once or twice — covers edge cases like
        // a transient resolver mismatch between dokwe's DNS view and ours.
        throw new RuntimeException(
            "Custom domain {$domain} not yet verified on dokwe — retrying",
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('AttachCustomDomainToDokwe failed after retries', [
            'tenant_id' => $this->tenantId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    private function failTerminally(Throwable $e, string $context): void
    {
        Log::error("AttachCustomDomainToDokwe: terminal failure during {$context}", [
            'tenant_id' => $this->tenantId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
        $this->fail($e);
    }
}
