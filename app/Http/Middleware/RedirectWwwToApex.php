<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301-redirects `www.<host>` to `<host>` so the tenant resolver never
 * sees `www` as a tenant slug.
 *
 * Why this exists: DomainTenantFinder::isCentralHost does an exact
 * string match against CENTRAL_DOMAIN (e.g. `keverna.com`). Without
 * this middleware, `www.keverna.com` failed that check, fell through
 * to the `{slug}.keverna.com` matcher, and got treated as a tenant
 * with `slug = 'www'` — meaning the marketing site rendered as a
 * would-be property/tenant landing page.
 *
 * Runs before every other custom middleware (prepended last in
 * bootstrap/app.php) so `www` never reaches ForceHttpsInProduction,
 * RedirectOldSlug, or the tenant resolver.
 */
class RedirectWwwToApex
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if (! str_starts_with(strtolower($host), 'www.')) {
            return $next($request);
        }

        $apex = substr($host, 4);
        $scheme = $request->isSecure() ? 'https' : 'http';
        $target = $scheme.'://'.$apex.$request->getRequestUri();

        return redirect()->away($target, 301);
    }
}
