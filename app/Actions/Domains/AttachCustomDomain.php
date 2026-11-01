<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Models\Tenant;
use App\Multitenancy\HostTenantFinder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attaches a custom domain to a tenant (PRD §6.6.2):
 *  - Refuses platform addresses and a domain another business already has.
 *  - Generates a fresh `centurion-verify=<token>` for the operator to set
 *    as a TXT record on `_centurion-verify.<domain>` (VERIFY_LABEL).
 *  - Resets verification and the live check, and flips status to Pending.
 *  - Dispatches the first VerifyCustomDomain attempt.
 *
 * Calling this again with the same domain rotates the token so a previously
 * shared instruction list cannot be reused to claim a domain after support
 * cleared it.
 */
class AttachCustomDomain
{
    /** The TXT record lives on its own name, so it can sit beside a CNAME. */
    public const VERIFY_LABEL = '_centurion-verify';

    public function handle(Tenant $tenant, string $domain): Tenant
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        if (HostTenantFinder::isPlatformHost($domain) || HostTenantFinder::slugFromHost($domain) !== null) {
            throw ValidationException::withMessages([
                'custom_domain' => __('Use a domain you own. :host is already your address here.', [
                    'host' => $tenant->slug.config('keverna.tenant_domain_suffix'),
                ]),
            ]);
        }

        $clash = Tenant::query()
            ->where('custom_domain', $domain)
            ->where('id', '!=', $tenant->id)
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'custom_domain' => __('Another business already uses that domain.'),
            ]);
        }

        $token = 'centurion-verify='.Str::lower(Str::random(32));

        $tenant->forceFill([
            'custom_domain' => $domain,
            'custom_domain_verification_token' => $token,
            'custom_domain_status' => CustomDomainStatus::Pending,
            'domain_verified_at' => null,
            'custom_domain_live_at' => null,
            'custom_domain_failed_checks' => 0,
            'custom_domain_dokwe_id' => $domain === $tenant->custom_domain ? $tenant->custom_domain_dokwe_id : null,
        ])->save();

        VerifyCustomDomain::dispatch($tenant->id);

        return $tenant->refresh();
    }
}
