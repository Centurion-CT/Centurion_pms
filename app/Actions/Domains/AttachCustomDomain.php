<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attaches a custom domain to a tenant (PRD §6.6.2):
 *  - Validates the domain is not already in use by another tenant.
 *  - Generates a fresh `keverna-verify=<token>` for the operator to set as
 *    a TXT record on their domain.
 *  - Resets domain_verified_at and flips status to Pending.
 *  - Dispatches the first VerifyCustomDomain attempt.
 *
 * Calling this again with the same domain rotates the token so a previously
 * shared instruction list cannot be reused to claim a domain after Keverna
 * support cleared it.
 */
class AttachCustomDomain
{
    public function handle(Tenant $tenant, string $domain): Tenant
    {
        $domain = strtolower(trim($domain));

        $clash = Tenant::query()
            ->where('custom_domain', $domain)
            ->where('id', '!=', $tenant->id)
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'custom_domain' => __('That domain is already attached to another Keverna property.'),
            ]);
        }

        $token = 'keverna-verify='.Str::lower(Str::random(32));

        $tenant->forceFill([
            'custom_domain' => $domain,
            'custom_domain_verification_token' => $token,
            'custom_domain_status' => CustomDomainStatus::Pending,
            'domain_verified_at' => null,
        ])->save();

        VerifyCustomDomain::dispatch($tenant->id);

        return $tenant->refresh();
    }
}
