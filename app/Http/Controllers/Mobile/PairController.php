<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\OperatorResource;
use App\Http\Resources\Mobile\TenantResource;
use App\Models\MobileDevice;
use App\Models\MobilePairingCode;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Consumes a MobilePairingCode: turns the one-shot code into a
 * long-lived Sanctum token bound to a MobileDevice row. Anonymous
 * endpoint — this is how a fresh install gets its first token.
 *
 * Flow:
 *   Web dashboard → generates a code (5-min TTL) + renders it as a QR.
 *   App scans QR  → POST /api/mobile/v1/pair with the code + device info.
 *   Us here      → verify + create MobileDevice + issue token + return.
 *
 * Race-safe via a SELECT ... FOR UPDATE inside a transaction so two
 * concurrent scans of the same code can't both succeed.
 */
class PairController extends Controller
{
    public function consume(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:8'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'device_name' => ['required', 'string', 'max:120'],
            'app_version' => ['required', 'string', 'max:32'],
            'push_token' => ['nullable', 'string', 'max:500'],
        ]);

        $tenant = Tenant::current();
        if ($tenant === null) {
            return response()->json(['error' => 'no_tenant'], 404);
        }

        $now = now();

        try {
            [$device, $token] = DB::transaction(function () use ($data, $tenant, $now, $request) {
                // Locked read — two racing pairs would otherwise both
                // pass the "not consumed" check.
                $code = MobilePairingCode::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $data['code'])
                    ->lockForUpdate()
                    ->first();

                if ($code === null) {
                    abort(response()->json(['error' => 'code_invalid'], 404));
                }
                if ($code->consumed_at !== null) {
                    abort(response()->json(['error' => 'code_already_consumed'], 409));
                }
                if ($code->expires_at->isPast()) {
                    abort(response()->json(['error' => 'code_expired'], 410));
                }

                $device = MobileDevice::query()->create([
                    'tenant_id' => $tenant->id,
                    'operator_id' => $code->operator_id,
                    'platform' => $data['platform'],
                    'device_name' => $data['device_name'],
                    'app_version' => $data['app_version'],
                    'app' => LoginController::appFromRequest($request),
                    'push_token' => $data['push_token'] ?? null,
                    'paired_ip' => $request->ip(),
                    'paired_at' => $now,
                    'last_seen_at' => $now,
                ]);

                $code->forceFill([
                    'consumed_at' => $now,
                    'consumed_by_mobile_device_id' => $device->id,
                ])->save();

                // Token name embeds the device id so
                // EnsureMobileDeviceActive can look up the linked
                // device without a second column.
                $token = $code->operator->createToken(
                    name: 'mobile-device-'.$device->id,
                    abilities: ['mobile:*'],
                );

                return [$device, $token];
            });
        } catch (HttpException $e) {
            // Re-throw abort() responses so the JSON error surfaces
            // instead of a generic 500.
            throw $e;
        }

        $operator = $device->operator;

        return response()->json([
            'token' => $token->plainTextToken,
            'operator' => new OperatorResource($operator),
            'tenant' => new TenantResource($tenant),
            'device' => [
                'id' => $device->id,
                'device_name' => $device->device_name,
                'paired_at' => $device->paired_at->toIso8601String(),
            ],
        ]);
    }
}
