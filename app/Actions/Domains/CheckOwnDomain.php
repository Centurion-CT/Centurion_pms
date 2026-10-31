<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Enums\CustomDomainStatus;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks a business's verified own domain, over HTTPS, which business it
 * serves (`/__tenant-ping`). One answer covers the DNS pointing at us,
 * the certificate and the host resolving to this business.
 *
 * A right answer makes the domain live: links and the booking site move
 * to it. After MISSES_BEFORE_PAUSE wrong answers in a row it stops being
 * live, so guests stay on the platform address until it answers again.
 * Run hourly by `domains:check-own` and by "Check again" on /domain.
 */
class CheckOwnDomain
{
    public const MISSES_BEFORE_PAUSE = 3;

    public function handle(Tenant $tenant): bool
    {
        if ($tenant->custom_domain === null || $tenant->domain_verified_at === null) {
            return false;
        }

        if ($this->answersForBusiness($tenant)) {
            $tenant->forceFill([
                'custom_domain_status' => CustomDomainStatus::Active,
                'custom_domain_live_at' => $tenant->custom_domain_live_at ?? Carbon::now(),
                'custom_domain_failed_checks' => 0,
            ])->save();

            return true;
        }

        $misses = $tenant->custom_domain_failed_checks + 1;
        $tenant->forceFill([
            'custom_domain_failed_checks' => $misses,
            'custom_domain_live_at' => $misses >= self::MISSES_BEFORE_PAUSE ? null : $tenant->custom_domain_live_at,
        ])->save();

        return false;
    }

    private function answersForBusiness(Tenant $tenant): bool
    {
        try {
            $response = Http::timeout(8)->withoutRedirecting()->acceptJson()
                ->get('https://'.$tenant->custom_domain.'/__tenant-ping');
        } catch (Throwable) {
            return false;
        }

        return $response->ok() && (int) $response->json('tenant_id') === (int) $tenant->id;
    }
}
