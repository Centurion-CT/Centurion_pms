<?php

declare(strict_types=1);

use App\Models\MobileDevice;
use App\Models\Operator;
use App\Services\Push\PushDriver;
use Tests\Support\FakePushDriver;

require_once __DIR__.'/HostTestHelpers.php';

/*
 * Shared helpers for the Push*Test files (not a test file itself).
 */

if (! function_exists('fakePush')) {
    /** Binds a recording push driver in the container. */
    function fakePush(): FakePushDriver
    {
        $fake = new FakePushDriver;
        app()->instance(PushDriver::class, $fake);

        return $fake;
    }

    /**
     * A host-app phone with a push token (operator's tenant must be current).
     */
    function pushDevice(Operator $op, string $token, array $attrs = []): MobileDevice
    {
        return MobileDevice::query()->create(array_merge([
            'tenant_id' => $op->tenant_id,
            'operator_id' => $op->id,
            'platform' => 'android',
            'device_name' => 'Pixel',
            'app_version' => '1.0.0',
            'app' => MobileDevice::APP_HOST,
            'push_token' => $token,
            'push_platform' => 'android',
            'paired_at' => now(),
            'last_seen_at' => now(),
        ], $attrs));
    }
}
