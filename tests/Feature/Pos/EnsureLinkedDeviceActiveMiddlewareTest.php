<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosDeviceSession;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'link-mw']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Bar',
        'type' => OutletType::Bar,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
    $this->operator->outlets()->attach($this->outlet->id);
});

afterEach(fn () => Tenant::forgetCurrent());

function activeDevice(Operator $op, array $overrides = []): PosDeviceSession
{
    return PosDeviceSession::query()->create(array_merge([
        'operator_id' => $op->id,
        'token_hash' => hash('sha256', 'test-'.$op->id.'-'.uniqid()),
        'device_label' => 'Test Phone',
        'user_agent' => 'Test/1.0',
        'paired_ip' => '127.0.0.1',
        'paired_at' => now(),
    ], $overrides));
}

it('desktop kiosk session without pos_device_session_id passes through', function (): void {
    $this->actingAs($this->operator, 'web')
        ->get('/dashboard/pos/heartbeat')
        ->assertOk()
        ->assertSee('ok');
});

it('phone session with active linked device passes and bumps last_seen_at', function (): void {
    $device = activeDevice($this->operator, ['last_seen_at' => now()->subHour()]);

    $this->actingAs($this->operator, 'web')
        ->withSession(['pos_device_session_id' => $device->id])
        ->get('/dashboard/pos/heartbeat')
        ->assertOk();

    expect($device->fresh()->last_seen_at?->diffInSeconds(now()))->toBeLessThan(5);
});

it('phone session with a revoked device is booted with 401 + device-revoked view', function (): void {
    $device = activeDevice($this->operator, [
        'revoked_at' => now(),
        'revoked_by_operator_id' => $this->operator->id,
    ]);

    $response = $this->actingAs($this->operator, 'web')
        ->withSession(['pos_device_session_id' => $device->id])
        ->get('/dashboard/pos/heartbeat');

    $response->assertStatus(401);
    $response->assertSee('This device was unlinked');
});

it('phone session pointing at a missing row is booted with 401', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->withSession(['pos_device_session_id' => 999_999])
        ->get('/dashboard/pos/heartbeat');

    $response->assertStatus(401);
    $response->assertSee('This device was unlinked');
});

it('last_seen_at is throttled — not written twice within a minute', function (): void {
    $device = activeDevice($this->operator, ['last_seen_at' => now()->subSeconds(10)]);
    $before = $device->last_seen_at?->timestamp;

    $this->actingAs($this->operator, 'web')
        ->withSession(['pos_device_session_id' => $device->id])
        ->get('/dashboard/pos/heartbeat')
        ->assertOk();

    expect($device->fresh()->last_seen_at?->timestamp)->toBe($before);
});
