<?php

declare(strict_types=1);

namespace App\Multitenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

/**
 * Finds the business a request belongs to (docs/MULTITENANCY.md).
 *
 * In `single` mode every request binds the first business, whatever the
 * host. That is how the install ran before multi-tenancy, and what
 * production keeps running until the wildcard DNS is live.
 *
 * In `domains` mode the host decides:
 *   1. a verified own domain (custom_domain + domain_verified_at)
 *   2. `{slug}<tenant_domain_suffix>`, e.g. grandpalace.centurionpms.com
 * The central domain, its `www.` and reserved subdomains (console, api…)
 * bind no business. Archived businesses are not found. Locked (paused)
 * ones are, so their "not taking bookings" page can show their details.
 *
 * The answer is cached on the request itself (NeedsTenant, the mobile API
 * middleware and Livewire all ask), so nothing outlives the request.
 * `forget()` clears it, e.g. right after sign-up creates a business.
 */
class HostTenantFinder extends TenantFinder
{
    private const ATTRIBUTE = 'centurion.tenant';

    public function findForRequest(Request $request): ?IsTenant
    {
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, self::isDomainMode()
                ? $this->forHost($request->getHost())
                : $this->firstBusiness());
        }

        return $request->attributes->get(self::ATTRIBUTE);
    }

    public function forHost(string $host): ?Tenant
    {
        $host = strtolower(rtrim($host, '.'));

        if (self::isPlatformHost($host)) {
            return null;
        }

        $tenant = Tenant::query()
            ->where('custom_domain', $host)
            ->whereNotNull('domain_verified_at')
            ->first();

        if ($tenant === null) {
            $slug = self::slugFromHost($host);
            if ($slug === null) {
                return null;
            }
            $tenant = Tenant::query()->where('slug', $slug)->first();
        }

        return $tenant !== null && $tenant->status !== TenantStatus::Archived ? $tenant : null;
    }

    public static function isDomainMode(): bool
    {
        return config('keverna.tenancy_mode') === 'domains';
    }

    /**
     * The central domain, `www.` + central, and reserved subdomains of the
     * tenant suffix (console.centurionpms.com…). These serve the platform,
     * never a business.
     */
    public static function isPlatformHost(string $host): bool
    {
        $host = strtolower($host);
        $central = strtolower((string) config('keverna.central_domain'));

        if ($host === $central || $host === 'www.'.$central) {
            return true;
        }

        $slug = self::slugFromHost($host);

        return $slug !== null && in_array($slug, (array) config('keverna.reserved_slugs'), true);
    }

    /**
     * `grandpalace` for `grandpalace.centurionpms.com`; null for hosts
     * outside the tenant suffix or nested subdomains (a.b.centurionpms.com).
     */
    public static function slugFromHost(string $host): ?string
    {
        $suffix = '.'.ltrim(strtolower((string) config('keverna.tenant_domain_suffix')), '.');

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        return $slug === '' || str_contains($slug, '.') ? null : $slug;
    }

    public static function forget(?Request $request = null): void
    {
        ($request ?? request())->attributes->remove(self::ATTRIBUTE);
    }

    private function firstBusiness(): ?Tenant
    {
        return Tenant::query()->oldest('id')->first();
    }
}
