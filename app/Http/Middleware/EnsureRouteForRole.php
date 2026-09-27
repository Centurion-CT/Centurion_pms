<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OperatorRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side gate for role-based dashboard routes. The route's
 * `module` middleware argument names the access bucket; the
 * operator's {@see \App\Models\Operator::canAccessModule()} decides
 * yes/no. That method unions the role's default modules with any
 * module-unlock permission grants on the operator, so a granted
 * permission is respected here without a middleware rewrite.
 *
 * Used inline on the route: `->middleware('role:bookings')`.
 *
 * If the operator isn't authenticated the auth middleware handles
 * the redirect first. If they are auth'd but don't have the module,
 * we send them to their role's home route with a flash — better UX
 * than a flat 403 for the "wrong door" case.
 */
class EnsureRouteForRole
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        /** @var \App\Models\Operator|null $operator */
        $operator = $request->user('web');
        if ($operator === null) {
            return redirect()->route('login');
        }

        if (! $operator->canAccessModule($module)) {
            $role = $operator->role ?? OperatorRole::Manager;
            session()->flash('error', "You don't have access to that section.");

            return redirect()->route($role->homeRoute());
        }

        return $next($request);
    }
}
