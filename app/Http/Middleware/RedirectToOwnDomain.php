<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once a business's own domain is live, its booking-site pages on the
 * platform address 301 to the own domain (docs/MULTITENANCY.md, step 4).
 *
 * Only the public browsing pages listed here move. Staff pages, payment
 * returns, signed links, webhooks and the mobile API keep working on
 * both addresses: they carry sessions or signatures tied to the host
 * they started on.
 */
class RedirectToOwnDomain
{
    /** Route names of the booking site's public pages. */
    public const BOOKING_PAGES = [
        'tenant.landing',
        'tenant.branch.*',
        'tenant.categories.show',
        'tenant.rooms.browse',
        'tenant.rooms.show',
        'tenant.units.browse',
        'tenant.workspaces.browse',
        'marketplace.stays.show',
        'marketplace.stays.book',
        'legal.*',
        'tenant.sitemap',
        'tenant.robots',
        'llms-guide',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::current();

        if ($tenant instanceof Tenant
            && $tenant->ownDomainIsLive()
            && $request->isMethodSafe()
            && strtolower($request->getHost()) !== $tenant->custom_domain
            && $request->routeIs(...self::BOOKING_PAGES)
        ) {
            return redirect()->away('https://'.$tenant->custom_domain.$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
