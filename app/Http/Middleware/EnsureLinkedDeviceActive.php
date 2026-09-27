<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Tenant\PosDevicePairingController;
use App\Models\PosDeviceSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces revocation for POS phones paired via the QR flow.
 *
 * Desktop kiosk sessions are unaffected — the middleware only kicks in
 * when the session carries a `pos_device_session_id` marker (set by
 * {@see PosDevicePairingController::claim}).
 *
 * When present:
 *  - Missing row (deleted operator, DB purge)   → log out, show revoked view
 *  - Row with a non-null `revoked_at`           → log out, show revoked view
 *  - Otherwise                                  → bump `last_seen_at`
 *    (throttled to once per 60s so a rapid burst of Livewire updates
 *    doesn't hammer the DB)
 */
class EnsureLinkedDeviceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->session()->get('pos_device_session_id');
        if ($sessionId === null) {
            return $next($request);
        }

        $device = PosDeviceSession::query()->find((int) $sessionId);

        if ($device === null || $device->revoked_at !== null) {
            $request->session()->forget('pos_device_session_id');
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->view('errors.pos-device-revoked', [], 401);
        }

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }
}
