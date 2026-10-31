<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SlugRedirect;
use App\Models\Tenant;
use App\Multitenancy\HostTenantFinder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301-redirects requests to a tenant's previous slug to their current slug
 * for 90 days after a rename (PRD §6.6.1).
 *
 * Global, so it runs before routing and catches every path on the old
 * address. Only in TENANCY_MODE=domains, and only when the host belongs
 * to no business today: the old address is then looked up in
 * `slug_redirects` and, if unexpired, sent to the business's current
 * address with the path and query kept.
 */
class RedirectOldSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! HostTenantFinder::isDomainMode()) {
            return $next($request);
        }

        $oldSlug = HostTenantFinder::slugFromHost(strtolower($request->getHost()));
        if ($oldSlug === null || app(HostTenantFinder::class)->findForRequest($request) !== null) {
            return $next($request);
        }

        $redirect = SlugRedirect::query()
            ->where('from_slug', $oldSlug)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        $toTenant = $redirect === null ? null : Tenant::query()->find($redirect->to_tenant_id);
        if ($toTenant === null) {
            return $next($request);
        }

        $port = in_array($request->getPort(), [80, 443], true) ? '' : ':'.$request->getPort();
        $target = $request->getScheme().'://'.$toTenant->primaryHost().$port.$request->getRequestUri();

        return redirect()->away($target, 301);
    }
}
