<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Belt-and-braces guard for the admin panel (PRD §18):
 *
 *   - The `auth:admin` middleware that runs alongside this one already
 *     rejects unauthenticated requests, so we only have to check the
 *     `is_active` flag here.
 *   - A previously-active admin who is later deactivated keeps their
 *     session cookie; this middleware logs them out the next time they
 *     try to do anything.
 */
class EnsureActiveAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        if ($admin instanceof Admin && ! $admin->is_active) {
            auth()->guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Account is deactivated.');
        }

        return $next($request);
    }
}
