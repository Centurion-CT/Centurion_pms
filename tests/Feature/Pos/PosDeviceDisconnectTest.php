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
    $this->tenant = Tenant::factory()->create(['slug' => 'disconnect-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
    $this->operator->outlets()->attach($this->outlet->id);
    $this->device = PosDeviceSession::query()->create([
        'operator_id' => $this->operator->id,
        'token_hash' => hash('sha256', 'disconnect-test-token'),
        'device_label' => 'Test Phone',
        'user_agent' => 'Test/1.0',
        'paired_ip' => '127.0.0.1',
        'paired_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('paired phone can self-disconnect — revokes the device + signs out', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->withSession(['pos_device_session_id' => $this->device->id, '_token' => 'test-csrf'])
        ->post('/dashboard/pos/link-device/disconnect', ['_token' => 'test-csrf']);

    $response->assertRedirect(route('tenant.pos.device-disconnected'));

    $fresh = $this->device->fresh();
    expect($fresh->revoked_at)->not->toBeNull();
    expect($fresh->revoked_by_operator_id)->toBe($this->operator->id);
    expect(auth('web')->check())->toBeFalse();
});

it('disconnect landing page renders friendly copy for a signed-out phone', function (): void {
    $this->get('/pos/device-disconnected')
        ->assertOk()
        ->assertSee('Signed out.')
        ->assertSeeHtml('data-testid="device-disconnected"');
});

it('disconnect from a session without pos_device_session_id still signs the operator out', function (): void {
    // Manager on the desktop kiosk shouldn't ever hit this route but if
    // they do (e.g. someone crafts the POST), no session marker means
    // no device to revoke — but we still log them out defensively.
    $response = $this->actingAs($this->operator, 'web')
        ->withSession(['_token' => 'test-csrf'])
        ->post('/dashboard/pos/link-device/disconnect', ['_token' => 'test-csrf']);

    $response->assertRedirect(route('tenant.pos.device-disconnected'));
    expect(auth('web')->check())->toBeFalse();
    // Original device row untouched — no marker in session means we
    // didn't know which row to revoke.
    expect($this->device->fresh()->revoked_at)->toBeNull();
});
