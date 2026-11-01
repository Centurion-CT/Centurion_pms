<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MobileDevice;
use App\Models\Operator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the Keverna Desk endpoints (`/api/mobile/v1/desk/*`). Runs
 * after auth:sanctum + mobile.device.
 *
 *  desk:device    the desk's own token (set up / operators / unlock). A
 *                 phone's device token is refused, and so is a desk whose
 *                 set-up person no longer holds a host role.
 *  desk:operator  an operator token issued by desk/unlock (lock).
 */
class EnsureDeskToken
{
    public function handle(Request $request, Closure $next, string $kind = 'device'): Response
    {
        $device = $request->attributes->get('mobile_device');
        $tokenKind = $request->attributes->get(EnsureMobileDeviceActive::TOKEN_KIND_ATTRIBUTE);

        if (! $device instanceof MobileDevice || ! $device->isDesk()) {
            return response()->json([
                'error' => 'not_a_desk',
                'detail' => 'This device is not set up as a '.config('brand.name').' Desk.',
            ], 403);
        }

        if ($kind === 'operator') {
            if ($tokenKind !== EnsureMobileDeviceActive::KIND_DESK_OPERATOR) {
                return response()->json(['error' => 'invalid_token_scope'], 403);
            }

            return $next($request);
        }

        if ($tokenKind !== EnsureMobileDeviceActive::KIND_DEVICE) {
            return response()->json(['error' => 'invalid_token_scope'], 403);
        }

        $operator = $request->user();
        if (! $operator instanceof Operator || ! in_array($operator->role, EnsureHostAppOperator::ALLOWED_ROLES, true)) {
            return response()->json([
                'error' => 'role_not_supported',
                'detail' => 'Only a manager or front desk officer can set up this desk.',
            ], 403);
        }

        return $next($request);
    }
}
