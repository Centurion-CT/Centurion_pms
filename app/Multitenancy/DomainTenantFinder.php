<?php

declare(strict_types=1);

namespace App\Multitenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

/**
 * Resolves the current tenant from the request host.
 *
 * Resolution order (PRD §6.6.1, §6.6.2):
 *   1. Verified custom domain (custom_domain + domain_verified_at)
 *   2. Subdomain on the central tenant suffix ({slug}.keverna.com)
 *
 * Central + admin domains are intentionally NOT tenant-bearing — returning null
 * tells Spatie's middleware to skip tenant binding, which is what central routes
 * expect.
 */
class DomainTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?IsTenant
    {
        $host = strtolower($request->getHost());

        if ($this->isCentralHost($host) || $this->isAdminHost($host)) {
            return null;
        }

        // 1. Verified custom domain wins (e.g. reservations.grandpalace.com)
        $tenant = Tenant::query()
            ->where('custom_domain', $host)
            ->whereNotNull('domain_verified_at')
            ->first();

        if ($tenant !== null) {
            return $this->onlyIfReachable($tenant);
        }

        // 2. {slug}.keverna.com (or whatever tenant_domain_suffix is configured)
        $suffix = ltrim((string) config('keverna.tenant_domain_suffix'), '.');
        $expectedSuffix = '.'.$suffix;

        if (! str_ends_with($host, $expectedSuffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($expectedSuffix));
        if ($slug === '' || str_contains($slug, '.')) {
            // Nested subdomains (e.g. foo.bar.keverna.com) are not tenants.
            return null;
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();

        return $tenant !== null ? $this->onlyIfReachable($tenant) : null;
    }

    private function isCentralHost(string $host): bool
    {
        return $host === strtolower((string) config('keverna.central_domain'));
    }

    private function isAdminHost(string $host): bool
    {
        return $host === strtolower((string) config('keverna.admin_domain'));
    }

    /**
     * Archived tenants are not resolved (data is retained but their public/dashboard
     * surfaces should return 404). Locked tenants ARE still resolved so the
     * "temporarily unavailable" page can render with their branding.
     */
    private function onlyIfReachable(Tenant $tenant): ?Tenant
    {
        return $tenant->status === TenantStatus::Archived ? null : $tenant;
    }
}
