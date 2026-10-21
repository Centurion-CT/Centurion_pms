<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301-redirects bare HTTP to HTTPS when running in production (PRD §19).
 * Local + testing bypass — both run plaintext for tooling convenience.
 *
 * Dokwe's ingress terminates TLS and normally rewrites HTTP → HTTPS at
 * the edge; this middleware is belt-and-braces for the rare case where
 * a request lands directly on the origin without going through Dokwe
 * (debugging tunnel, ops-side curl, etc.).
 */
class ForceHttpsInProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production') || ! config('keverna.force_https')) {
            return $next($request);
        }

        // Dokwe sets `X-Forwarded-Proto: https` even when the connection
        // to origin is HTTP. `isSecure()` reads that header, but only
        // when the proxy is trusted. Trust list is configured in
        // bootstrap/app.php via `$middleware->trustProxies(at: '*', …)`.
        if ($request->isSecure()) {
            return $next($request);
        }

        $url = 'https://'.$request->getHost().$request->getRequestUri();

        return redirect()->away($url, 301);
    }
}
