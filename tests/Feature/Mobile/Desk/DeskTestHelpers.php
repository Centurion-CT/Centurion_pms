<?php

declare(strict_types=1);

use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Str;

/*
 * Shared helpers for the Keverna Desk API tests (not a test file:
 * Pest only collects *Test.php). Loaded with require_once.
 */

require_once __DIR__.'/../Host/HostTestHelpers.php';

if (! function_exists('deskUrl')) {
    function deskUrl(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/desk/'.ltrim($path, '/');
    }

    function deskLoginUrl(): string
    {
        return 'http://'.config('keverna.central_domain').'/api/mobile/v1/login';
    }

    /**
     * A desk set up by $op, the way central login with
     * `X-Keverna-App: desk` creates it. Tenant must be current.
     *
     * @return array{device: MobileDevice, token: string}
     */
    function makeDesk(Operator $op, ?int $propertyId = null): array
    {
        $device = MobileDevice::query()->create([
            'tenant_id' => $op->tenant_id,
            'operator_id' => $op->id,
            'property_id' => $propertyId,
            'platform' => 'desktop',
            'app' => MobileDevice::APP_DESK,
            'device_name' => 'Reception PC',
            'app_version' => '1.0.0',
            'paired_at' => now(),
            'last_seen_at' => now(),
        ]);
        $token = $op->createToken('mobile-device-'.$device->id, ['mobile:*'])->plainTextToken;

        return ['device' => $device, 'token' => $token];
    }

    /** Bearer headers; resets the guard like a fresh production request. */
    function deskBearer(string $token, array $extra = []): array
    {
        app('auth')->forgetGuards();

        return array_merge(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], $extra);
    }

    function deskKey(): array
    {
        return ['Idempotency-Key' => (string) Str::ulid()];
    }
}
