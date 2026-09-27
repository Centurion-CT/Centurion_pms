<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Multitenancy\TenantMediaDisk;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Swap the media-library disk to the tenant's own Dokwe Storage space
 * when they've connected it via the Integrations page. Runs inside the
 * tenant middleware group AFTER Spatie's NeedsTenant so
 * `Tenant::current()` is guaranteed populated.
 *
 * No-op when the tenant hasn't connected — MediaLibrary keeps using
 * the platform `MEDIA_DISK`. Every existing Media row already stores
 * its own `disk` column, so files uploaded before the swap continue
 * to resolve against whatever disk they were saved to; only the *next*
 * `addMedia()` call flows to the new disk.
 */
class ConfigureTenantMediaDisk
{
    public function __construct(private readonly TenantMediaDisk $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::current();
        if ($tenant !== null) {
            $this->resolver->configure($tenant);
        }

        return $next($request);
    }
}
