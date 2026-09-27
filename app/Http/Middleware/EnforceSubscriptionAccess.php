<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Detects expired-trial (or otherwise-expired) tenants and flags the request
 * as read-only. Two side effects:
 *
 *  1. Binds `billing.read_only = true` in the container. The global
 *     Eloquent write guard in AppServiceProvider checks this flag to
 *     block mutations on tenant-scoped models.
 *
 *  2. Shares `$billingReadOnly` with every view rendered on this request
 *     so `layouts/tenant-auth.blade.php` can show the "renew to keep
 *     making changes" bottom bar.
 *
 * Sits inside the RequireCompleteOnboarding group so it only runs once
 * we know the operator + tenant are resolved.
 */
class EnforceSubscriptionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::current();
        $readOnly = $tenant instanceof Tenant && $tenant->isReadOnlyForBilling();

        if ($readOnly) {
            app()->instance('billing.read_only', true);
        }

        View::share('billingReadOnly', $readOnly);

        return $next($request);
    }
}
