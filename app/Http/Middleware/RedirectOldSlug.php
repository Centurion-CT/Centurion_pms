<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SlugRedirect;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301-redirects requests to a tenant's previous slug to their current slug
 * for 90 days after a rename (PRD §6.6.1).
 *
 * Runs AFTER ResolveTenantFromRequest. If a tenant was already bound (i.e.
 * the host matched a current slug or verified custom domain) we pass through.
 * Otherwise we look up the host's slug portion in `slug_redirects` and, if
 * unexpired, 301 to the new host preserving path + query.
 */
class RedirectOldSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Tenant::checkCurrent()) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $suffix = ltrim((string) config('keverna.tenant_domain_suffix'), '.');
        $expectedSuffix = '.'.$suffix;

        if (! str_ends_with($host, $expectedSuffix)) {
            return $next($request);
        }

        $oldSlug = substr($host, 0, -strlen($expectedSuffix));
        if ($oldSlug === '' || str_contains($oldSlug, '.')) {
            return $next($request);
        }

        $redirect = SlugRedirect::query()
            ->where('from_slug', $oldSlug)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($redirect === null) {
            return $next($request);
        }

        $toTenant = Tenant::query()->find($redirect->to_tenant_id);
        if ($toTenant === null) {
            return $next($request);
        }

        $target = $request->getScheme().'://'.$toTenant->primaryHost().$request->getRequestUri();

        return redirect()->away($target, 301);
    }
}
