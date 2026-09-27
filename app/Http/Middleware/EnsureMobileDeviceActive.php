<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MobileDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:sanctum` on every /api/mobile/v1 route. Resolves
 * the MobileDevice bound to the current bearer token (encoded in the
 * token's name — set by PairController::consume as
 * "mobile-device-{id}"), 401s if it's been revoked, bumps
 * last_seen_at so the staff/devices page shows fresh liveness.
 *
 * Web + POS device sessions have their own middleware
 * (EnsureLinkedDeviceActive). Mobile is intentionally a separate
 * pipeline — different auth surface, different revocation UX.
 */
class EnsureMobileDeviceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->user();
        $token = $operator?->currentAccessToken();
        if ($operator === null || $token === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // Token name pattern: mobile-device-{id}. Anything else is
        // either a web-side or a legacy token — refuse it.
        if (! preg_match('/^mobile-device-(\d+)$/', $token->name, $m)) {
            return response()->json(['error' => 'invalid_token_scope'], 401);
        }

        $device = MobileDevice::query()->find((int) $m[1]);
        if ($device === null || $device->operator_id !== $operator->id) {
            return response()->json(['error' => 'device_not_found'], 401);
        }
        if (! $device->isActive()) {
            return response()->json(['error' => 'device_revoked'], 401);
        }

        // Cheap update, no timestamps() bump — avoids a UPDATE ...
        // SET updated_at on every mobile request. last_seen_at is
        // the only column that changes, and the query uses the PK.
        MobileDevice::query()
            ->whereKey($device->id)
            ->update(['last_seen_at' => now()]);

        // Stash on the request so downstream controllers can read
        // the device without a second lookup.
        $request->attributes->set('mobile_device', $device);

        return $next($request);
    }
}
