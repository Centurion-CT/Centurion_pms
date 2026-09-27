<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Staff\Devices;
use App\Models\Operator;
use App\Models\PosDeviceSession;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'staff-devs']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->posStaff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

function makeDevice(Operator $op, array $overrides = []): PosDeviceSession
{
    return PosDeviceSession::query()->create(array_merge([
        'operator_id' => $op->id,
        'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
        'device_label' => 'iPhone · Safari',
        'user_agent' => 'Test/1.0',
        'paired_ip' => '127.0.0.1',
        'paired_at' => now(),
    ], $overrides));
}

it('lists device sessions across all operators in the tenant', function (): void {
    $d1 = makeDevice($this->posStaff);
    $d2 = makeDevice($this->manager);

    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->assertSeeHtml("data-testid=\"device-row-{$d1->id}\"")
        ->assertSeeHtml("data-testid=\"device-row-{$d2->id}\"");
});

it('shows the empty state when no devices are paired', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->assertSee('No linked devices yet');
});

it('manager can revoke — sets revoked_at + revoked_by_operator_id', function (): void {
    $device = makeDevice($this->posStaff);

    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->call('revoke', $device->id)
        ->assertSee('Revoked');

    $device->refresh();
    expect($device->revoked_at)->not->toBeNull();
    expect($device->revoked_by_operator_id)->toBe($this->manager->id);
});

it('revoking an already-revoked device is a no-op', function (): void {
    $device = makeDevice($this->posStaff, [
        'revoked_at' => now()->subMinute(),
        'revoked_by_operator_id' => $this->manager->id,
    ]);
    $originalRevokedAt = $device->revoked_at->timestamp;

    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->call('revoke', $device->id);

    expect($device->fresh()->revoked_at->timestamp)->toBe($originalRevokedAt);
});

it('non-manager cannot revoke a device', function (): void {
    $device = makeDevice($this->posStaff);

    Livewire::actingAs($this->posStaff, 'web')
        ->test(Devices::class)
        ->call('revoke', $device->id)
        ->assertSee('Only managers can revoke');

    expect($device->fresh()->revoked_at)->toBeNull();
});

it('delete refuses to touch an active (non-revoked) device', function (): void {
    $device = makeDevice($this->posStaff);

    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->call('delete', $device->id)
        ->assertSee('Revoke the device first');

    expect(PosDeviceSession::query()->find($device->id))->not->toBeNull();
});

it('deleting a revoked device removes the row', function (): void {
    $device = makeDevice($this->posStaff, ['revoked_at' => now()]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->call('delete', $device->id);

    expect(PosDeviceSession::query()->find($device->id))->toBeNull();
});

it('non-manager hitting /dashboard/staff/devices is redirected by the role gate', function (): void {
    $this->actingAs($this->posStaff, 'web');
    $response = $this->get('/dashboard/staff/devices');
    // role:staff middleware redirects narrow roles to their home route.
    $response->assertRedirect();
});
