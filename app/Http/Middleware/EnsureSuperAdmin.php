<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gate routes that mutate platform-admin accounts.
 *
 * Layered on top of {@see EnsureActiveAdmin} — by the time this runs we
 * already know there's an authenticated, active admin; this just
 * checks they hold the 'super' role. Anyone else 403s rather than
 * silently redirecting, so it's obvious in audit logs.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        if ($admin === null || $admin->role !== 'super') {
            throw new AccessDeniedHttpException('Super admin access required.');
        }

        return $next($request);
    }
}
