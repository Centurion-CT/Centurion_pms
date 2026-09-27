<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\OperatorResource;
use App\Http\Resources\Mobile\TenantResource;
use App\Models\MobileDevice;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whoami + logout. Everything else is in feature-specific controllers.
 * Called by the app on cold-start to hydrate {operator, tenant} so the
 * UI has role + property info before the first user action.
 */
class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $operator = $request->user();
        $tenant = Tenant::current();

        return response()->json([
            'operator' => new OperatorResource($operator),
            'tenant' => new TenantResource($tenant),
            'device' => [
                'id' => optional($request->attributes->get('mobile_device'))->id,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $operator = $request->user();
        $token = $operator?->currentAccessToken();
        $device = $request->attributes->get('mobile_device');

        // Kill the Sanctum token AND soft-revoke the device — same
        // revocation UX as the web staff/devices page.
        if ($token !== null) {
            $token->delete();
        }
        if ($device instanceof MobileDevice) {
            $device->forceFill([
                'revoked_at' => now(),
                'revoked_by_operator_id' => $operator?->id,
            ])->save();
        }

        return response()->json(['status' => 'ok']);
    }
}
