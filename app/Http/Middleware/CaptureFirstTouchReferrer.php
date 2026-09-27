<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Marketplace\RateResolver;
use Closure;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-touch acquisition source capture. Guests browsing a
 * multi-branch tenant land on the org root first ("choose a branch"),
 * click through to a specific branch, and by the time the RateResolver
 * runs on the branch page the Referer header is now the tenant's own
 * subdomain — so a Google-referred guest looked "direct" and any
 * `referrers = ['google']` rate rule silently missed.
 *
 * This middleware stamps a `kv_ref` cookie on the FIRST request to any
 * marketplace surface (subdomain + /stays paths) that carries an
 * external referer. Subsequent intra-site navigation preserves the
 * original source. Cookie is scoped to the current session (Chrome
 * treats these as session cookies which clear on browser close) so a
 * returning visitor a week later re-establishes their source.
 *
 * Read side (RateResolver) prefers the cookie over the live Referer
 * header — see referrerFor().
 */
class CaptureFirstTouchReferrer
{
    public function __construct(
        private readonly RateResolver $resolver,
        private readonly CookieJar $cookies,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Cookie already set → guest is in an active session; nothing
        // to do. Prevents same-session Google→branch-chooser→branch
        // from overwriting itself back to "other".
        if ($request->cookie('kv_ref') !== null) {
            return $next($request);
        }

        $referer = (string) $request->headers->get('referer', '');
        if ($referer === '') {
            return $next($request);
        }

        // Same-host referer (an intra-site link) is NOT a first touch.
        $refHost = strtolower((string) parse_url($referer, PHP_URL_HOST));
        $reqHost = strtolower($request->getHost());
        if ($refHost === $reqHost || $refHost === '') {
            return $next($request);
        }

        // Canonicalise via the resolver's own mapper so the cookie's
        // vocabulary matches what RateRule.referrers stores.
        $source = $this->resolver->referrerFor($referer);
        if ($source === 'direct' || $source === '') {
            return $next($request);
        }

        // Response phase: queue the cookie so it goes out with the
        // rendered page. Lifetime = 0 → session cookie (clears when
        // the browser closes) so weekly return visitors get a fresh
        // source attribution.
        $response = $next($request);
        $this->cookies->queue(cookie('kv_ref', $source, 0, '/', null, false, true, false, 'Lax'));

        return $response;
    }
}
