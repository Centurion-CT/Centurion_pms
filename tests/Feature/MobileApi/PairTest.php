<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\MobileDevice;
use App\Models\MobilePairingCode;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * /api/mobile/v1/pair — the anonymous endpoint that turns a
 * short-lived MobilePairingCode into a Sanctum token bound to a
 * MobileDevice. Every subsequent mobile request rides that token.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'mobile-pair']);
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::HouseCleaner,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

function mobileApi(Tenant $tenant, string $path): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').$path;
}

function makePairingCode(Tenant $tenant, Operator $op, ?Carbon $expiresAt = null, ?Carbon $consumedAt = null): MobilePairingCode
{
    return MobilePairingCode::query()->create([
        'tenant_id' => $tenant->id,
        'operator_id' => $op->id,
        'code' => MobilePairingCode::generateCode(),
        'expires_at' => $expiresAt ?? now()->addMinutes(5),
        'consumed_at' => $consumedAt,
    ]);
}

it('exchanges a valid pairing code for a token + creates a MobileDevice', function (): void {
    $code = makePairingCode($this->tenant, $this->operator);

    $response = $this->postJson(mobileApi($this->tenant, '/api/mobile/v1/pair'), [
        'code' => $code->code,
        'platform' => 'ios',
        'device_name' => 'Test iPhone',
        'app_version' => '1.0.0+1',
        'push_token' => 'apns-fake-token',
    ]);

    $response->assertOk()->assertJsonStructure([
        'token',
        'operator' => ['id', 'name', 'email', 'role', 'role_label'],
        'tenant' => ['id', 'slug', 'name'],
        'device' => ['id', 'device_name', 'paired_at'],
    ]);

    expect(MobileDevice::query()->count())->toBe(1);
    $device = MobileDevice::query()->first();
    expect($device->operator_id)->toBe($this->operator->id);
    expect($device->platform)->toBe('ios');
    expect($device->push_token)->toBe('apns-fake-token');

    // Code is now consumed + linked to the device.
    expect($code->fresh()->consumed_at)->not->toBeNull();
    expect($code->fresh()->consumed_by_mobile_device_id)->toBe($device->id);
});

it('rejects an unknown code with 404', function (): void {
    $response = $this->postJson(mobileApi($this->tenant, '/api/mobile/v1/pair'), [
        'code' => 'XXXXYYY1',
        'platform' => 'ios',
        'device_name' => 'Test iPhone',
        'app_version' => '1.0.0+1',
    ]);

    $response->assertStatus(404)->assertJson(['error' => 'code_invalid']);
    expect(MobileDevice::query()->count())->toBe(0);
});

it('rejects an already-consumed code with 409', function (): void {
    $code = makePairingCode($this->tenant, $this->operator, consumedAt: now());

    $response = $this->postJson(mobileApi($this->tenant, '/api/mobile/v1/pair'), [
        'code' => $code->code,
        'platform' => 'ios',
        'device_name' => 'Test iPhone',
        'app_version' => '1.0.0+1',
    ]);

    $response->assertStatus(409)->assertJson(['error' => 'code_already_consumed']);
});

it('rejects an expired code with 410', function (): void {
    $code = makePairingCode($this->tenant, $this->operator, expiresAt: now()->subMinute());

    $response = $this->postJson(mobileApi($this->tenant, '/api/mobile/v1/pair'), [
        'code' => $code->code,
        'platform' => 'ios',
        'device_name' => 'Test iPhone',
        'app_version' => '1.0.0+1',
    ]);

    $response->assertStatus(410)->assertJson(['error' => 'code_expired']);
});
