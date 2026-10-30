<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Multitenancy\HostTenantFinder;
use Closure;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant from the request host before downstream
 * middleware (Spatie's NeedsTenant) checks for it.
 *
 * Spatie's library calls determineCurrentTenant() automatically — but ONLY when
 * `app()->runningInConsole()` is false. That excludes the test runner, which
 * means HTTP tests would never bind a tenant. Binding via middleware works
 * uniformly in console + HTTP and keeps the resolution path explicit (easier
 * to debug than implicit service-provider magic).
 */
class ResolveTenantFromRequest
{
    public function __construct(private HostTenantFinder $finder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->finder->findForRequest($request);

        if ($tenant instanceof IsTenant) {
            $tenant->makeCurrent();
        }

        return $next($request);
    }
}
