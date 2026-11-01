<?php

declare(strict_types=1);

namespace App\Jobs\Domains;

use App\Actions\Domains\AttachCustomDomain;
use App\Actions\Domains\CheckOwnDomain;
use App\Enums\CustomDomainStatus;
use App\Models\Tenant;
use App\Services\Dns\DnsResolver;
use App\Services\Dokwe\DokweClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Polls DNS for proof that the operator owns their custom domain
 * (PRD §6.6.2): the TXT record on `_centurion-verify.{custom_domain}`
 * must contain the per-tenant verification token. Pointing the domain
 * at us (CNAME or A) is checked separately, over HTTPS, by
 * CheckOwnDomain, which decides when guests move to it.
 *
 * On success: marks Active, stamps domain_verified_at, asks Dokwe for the
 * certificate when the API is configured, and runs the first live check.
 * On miss: re-schedules itself 15 minutes out, up to the 48-hour PRD
 * deadline. After the deadline the status flips to Error and the operator
 * can re-attach to start over.
 *
 * Idempotent: re-running on an already-Active tenant is a no-op.
 */
class VerifyCustomDomain implements NotTenantAware, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900; // 15 min — overlaps with the retry cadence

    private const POLL_INTERVAL_MINUTES = 15;

    private const MAX_AGE_HOURS = 48;

    public function __construct(public int $tenantId) {}

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function handle(DnsResolver $dns, CheckOwnDomain $live): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null
            || $tenant->custom_domain === null
            || $tenant->custom_domain_verification_token === null
        ) {
            return;
        }

        if ($tenant->custom_domain_status === CustomDomainStatus::Active) {
            return;
        }

        $txt = $dns->txt(AttachCustomDomain::VERIFY_LABEL.'.'.$tenant->custom_domain);

        if (in_array($tenant->custom_domain_verification_token, $txt, true)) {
            $tenant->forceFill([
                'custom_domain_status' => CustomDomainStatus::Active,
                'domain_verified_at' => Carbon::now(),
            ])->save();

            Log::info('Custom domain verified', [
                'tenant_id' => $tenant->id,
                'domain' => $tenant->custom_domain,
            ]);

            // Ownership confirmed — ask Dokwe for the certificate. Without
            // the API the domain is attached in the Dokwe dashboard by hand;
            // the live check notices either way.
            if (DokweClient::isConfigured()) {
                AttachCustomDomainToDokwe::dispatch($tenant->id);
            }

            $live->handle($tenant);

            return;
        }

        // Decide whether to keep polling or give up.
        $startedAt = $tenant->updated_at ?? Carbon::now();
        $ageHours = Carbon::now()->diffInHours($startedAt, absolute: true);

        if ($ageHours >= self::MAX_AGE_HOURS) {
            $tenant->forceFill([
                'custom_domain_status' => CustomDomainStatus::Error,
            ])->save();

            Log::warning('Custom domain verification gave up after 48h', [
                'tenant_id' => $tenant->id, 'domain' => $tenant->custom_domain,
            ]);

            return;
        }

        // Re-queue ourselves for the next 15-minute slot.
        self::dispatch($tenant->id)->delay(now()->addMinutes(self::POLL_INTERVAL_MINUTES));
    }
}
