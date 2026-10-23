<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Models\MobileDevice;
use App\Multitenancy\TenantScope;
use App\Services\Push\HostPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Push registration + per-device notification preferences for the host
 * app (docs/host-app/PHASE-5.md). Acts on the CURRENT device: the one
 * bound to the Sanctum token (`mobile-device-{id}`, resolved by the
 * mobile.device middleware). Calling any of these marks the device
 * `app = host`.
 */
class DeviceController extends HostController
{
    public function __construct(private readonly HostPush $push) {}

    /** PUT device/push {token|null, platform: ios|android} */
    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['present', 'nullable', 'string', 'max:500'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
        ]);
        $device = $this->device($request);
        $token = isset($data['token']) && trim((string) $data['token']) !== '' ? trim((string) $data['token']) : null;
        $now = Carbon::now();

        DB::transaction(function () use ($device, $token, $data, $now): void {
            if ($token !== null) {
                // One token, one device: a phone that signed in again (or
                // into another tenant) must not get the push twice.
                MobileDevice::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('push_token', $token)
                    ->where('id', '!=', $device->id)
                    ->update(['push_token' => null, 'push_token_updated_at' => $now]);
            }

            $device->forceFill([
                'app' => MobileDevice::APP_HOST,
                'push_token' => $token,
                'push_platform' => $token !== null ? $data['platform'] : null,
                'push_token_updated_at' => $now,
            ])->save();
        });

        return response()->json([
            'status' => 'ok',
            'push' => [
                'registered' => $token !== null,
                'platform' => $token !== null ? $data['platform'] : null,
                'push_available' => $this->push->available(),
            ],
        ]);
    }

    /** GET device/notifications */
    public function notifications(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $this->markHost($device);

        return response()->json(['data' => $this->settings($request, $device)]);
    }

    /** PUT device/notifications {prefs: {key: bool, ...}} (partial) */
    public function updateNotifications(Request $request): JsonResponse
    {
        $rules = ['prefs' => ['required', 'array']];
        foreach (HostPush::PREFS as $key) {
            $rules['prefs.'.$key] = ['sometimes', 'boolean'];
        }
        $request->validate($rules);
        $input = (array) $request->input('prefs', []);
        $unknown = array_diff(array_keys($input), HostPush::PREFS);
        if ($unknown !== []) {
            return response()->json([
                'error' => 'unknown_pref',
                'detail' => 'Unknown notification setting: '.implode(', ', $unknown).'.',
            ], 422);
        }

        $device = $this->device($request);
        $prefs = HostPush::prefs($device);
        foreach (HostPush::PREFS as $key) {
            if (array_key_exists($key, $input)) {
                $prefs[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $device->forceFill(['app' => MobileDevice::APP_HOST, 'push_prefs' => $prefs])->save();

        return response()->json(['status' => 'ok', 'data' => $this->settings($request, $device)]);
    }

    /**
     * @return array{push_available: bool, prefs: array<string, bool>, allowed: array<string, bool>}
     */
    private function settings(Request $request, MobileDevice $device): array
    {
        return [
            'push_available' => $this->push->available(),
            'prefs' => HostPush::prefs($device),
            'allowed' => HostPush::allowed($this->operator($request)),
        ];
    }

    private function device(Request $request): MobileDevice
    {
        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');

        return $device;
    }

    private function markHost(MobileDevice $device): void
    {
        if ($device->app !== MobileDevice::APP_HOST) {
            $device->forceFill(['app' => MobileDevice::APP_HOST])->save();
        }
    }
}
