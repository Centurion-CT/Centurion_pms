<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PosDeviceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Self-unlink for a paired POS phone. Mirrors what happens when an
 * admin revokes the device from Staff → Linked devices, but initiated
 * by the operator from the kiosk header — a "sign out AND stop this
 * phone from being trusted" action.
 *
 * Marks the device session revoked (so if the same session cookie
 * somehow got replayed, EnsureLinkedDeviceActive would boot it),
 * logs out, invalidates the session, and lands the operator on a
 * friendly "phone unlinked" page.
 */
class PosDeviceDisconnectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $sessionId = $request->session()->get('pos_device_session_id');
        $operatorId = Auth::guard('web')->id();

        if ($sessionId !== null) {
            $device = PosDeviceSession::query()->find((int) $sessionId);
            if ($device !== null && $device->revoked_at === null) {
                $device->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_operator_id' => $operatorId,
                ])->save();
            }
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.pos.device-disconnected');
    }
}
