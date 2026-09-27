<?php

declare(strict_types=1);

namespace App\Jobs\Domains;

use App\Enums\CustomDomainStatus;
use App\Models\Tenant;
use App\Services\Dns\DnsResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Polls DNS for the operator's custom domain (PRD §6.6.2):
 *  - CNAME of {custom_domain} must point to the configured CNAME target.
 *  - TXT of {custom_domain} must contain the per-tenant verification token.
 *
 * On success: marks Active + stamps domain_verified_at. On miss: re-schedules
 * itself 15 minutes out, up to the 48-hour PRD deadline. After the deadline
 * the status flips to Error and the operator can re-attach to start over.
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

    public function handle(DnsResolver $dns): void
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

        $cname = $dns->cname($tenant->custom_domain);
        $expectedTarget = strtolower((string) config('keverna.custom_domain_cname_target'));
        $cnameOk = $cname !== null && strtolower(rtrim($cname, '.')) === $expectedTarget;

        $txt = $dns->txt($tenant->custom_domain);
        $txtOk = in_array($tenant->custom_domain_verification_token, $txt, true);

        if ($cnameOk && $txtOk) {
            $tenant->forceFill([
                'custom_domain_status' => CustomDomainStatus::Active,
                'domain_verified_at' => Carbon::now(),
            ])->save();

            Log::info('Custom domain verified', [
                'tenant_id' => $tenant->id,
                'domain' => $tenant->custom_domain,
            ]);

            // DNS confirmed correct — kick off the dokwe-side attach. Safe
            // to do here (and not retried by us) because the dokwe job
            // owns its own retry budget and short-circuits if the column
            // is already populated.
            AttachCustomDomainToDokwe::dispatch($tenant->id);

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
                'cname_ok' => $cnameOk, 'txt_ok' => $txtOk,
            ]);

            return;
        }

        // Re-queue ourselves for the next 15-minute slot.
        self::dispatch($tenant->id)->delay(now()->addMinutes(self::POLL_INTERVAL_MINUTES));
    }
}
