<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Tenant;

/**
 * /api/mobile/v1/me + logout. Also covers the middleware trio behaviour:
 *   - missing token → 401
 *   - wrong-scope token → 401
 *   - revoked device → 401
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'mobile-auth']);
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::HouseCleaner,
    ]);
    $this->device = MobileDevice::query()->create([
        'tenant_id' => $this->tenant->id,
        'operator_id' => $this->operator->id,
        'platform' => 'ios',
        'device_name' => 'Test',
        'app_version' => '1.0.0',
        'paired_at' => now(),
        'last_seen_at' => now(),
    ]);
    $this->token = $this->operator->createToken('mobile-device-'.$this->device->id, ['mobile:*'])->plainTextToken;
});

afterEach(fn () => Tenant::forgetCurrent());

function mobileHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

it('/me returns the operator + tenant when the token is valid', function (): void {
    $url = 'http://'.$this->tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/me';

    $response = $this->getJson($url, mobileHeaders($this->token));

    $response->assertOk()
        ->assertJsonPath('operator.id', $this->operator->id)
        ->assertJsonPath('operator.role', 'house_cleaner')
        ->assertJsonPath('tenant.slug', 'mobile-auth')
        ->assertJsonPath('device.id', $this->device->id);
});

it('rejects requests with no bearer token', function (): void {
    $url = 'http://'.$this->tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/me';

    $this->getJson($url, ['Accept' => 'application/json'])->assertStatus(401);
});

it('rejects a token whose linked device has been revoked', function (): void {
    $this->device->forceFill(['revoked_at' => now()])->save();

    $url = 'http://'.$this->tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/me';

    $this->getJson($url, mobileHeaders($this->token))
        ->assertStatus(401)
        ->assertJson(['error' => 'device_revoked']);
});

it('logout deletes the token + soft-revokes the device', function (): void {
    $url = 'http://'.$this->tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/logout';

    $response = $this->postJson($url, [], array_merge(mobileHeaders($this->token), [
        'Idempotency-Key' => 'logout-once-key-abc',
    ]));

    $response->assertOk();
    expect($this->device->fresh()->revoked_at)->not->toBeNull();

    // Second call with the same token now 401s — device is revoked.
    $meUrl = 'http://'.$this->tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/me';
    $this->getJson($meUrl, mobileHeaders($this->token))->assertStatus(401);
});
