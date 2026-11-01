<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Property;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a paused business (status locked, or archived) from serving
 * anything but payment webhooks: guests see its "not taking online
 * bookings" page with its contact details, staff are signed out, and API
 * clients get a 403. Payments already in flight still land.
 */
class EnsureBusinessIsOpen
{
    /** Paths that must keep working while paused: payment providers calling back. */
    private const ALWAYS_OPEN = ['paystack/*', 'health'];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::current();

        if ($tenant === null || $tenant->status->isOperational() || $request->is(...self::ALWAYS_OPEN)) {
            return $next($request);
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => "{$tenant->name} isn't available right now."], 403);
        }

        $property = Property::query()->where('tenant_id', $tenant->id)->orderBy('id')->first();
        $place = $property === null ? null : implode(', ', array_filter([$tenant->name, $property->city, $property->state]));

        return response()->view('platform.business-paused', [
            'tenant' => $tenant,
            'host' => $request->getHost(),
            'place' => $place,
        ], 503);
    }
}
