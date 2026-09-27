<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Staff\Devices;
use App\Models\MobileDevice;
use App\Models\MobilePairingCode;
use App\Models\Operator;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Web-side of the pair flow — the Livewire Devices page. Operator
 * clicks "Generate pairing code", the component creates a
 * MobilePairingCode + renders a QR SVG payload. Cancel deletes the
 * unspent code.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pair-ui']);
    $this->tenant->makeCurrent();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('linkPhone creates a MobilePairingCode + hydrates the QR SVG', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->assertSet('mobileCode', null)
        ->call('linkPhone')
        ->assertSet('mobileCode', fn ($c) => is_string($c) && strlen($c) === 8)
        ->assertSet('mobileQrSvg', fn ($svg) => is_string($svg) && str_contains($svg, '<svg'));

    $code = MobilePairingCode::query()->first();
    expect($code)->not->toBeNull();
    expect($code->operator_id)->toBe($this->manager->id);
    expect($code->consumed_at)->toBeNull();
    expect($code->expires_at->isFuture())->toBeTrue();
});

it('linkPhone recycles unspent codes so calling twice does not stack rows', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->call('linkPhone')
        ->call('linkPhone');

    // Two rows total (both created), but only ONE is still unspent
    // (the first is expired-in-the-past by the second call).
    expect(MobilePairingCode::query()->count())->toBe(2);
    expect(MobilePairingCode::query()->where('expires_at', '>', now())->count())->toBe(1);
});

it('cancelPhoneLink expires the active code + clears the component state', function (): void {
    $c = Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->call('linkPhone')
        ->call('cancelPhoneLink')
        ->assertSet('mobileCode', null)
        ->assertSet('mobileQrSvg', null);

    expect(MobilePairingCode::query()->where('expires_at', '>', now())->count())->toBe(0);
});

it('revokeMobile flips revoked_at on a mobile device (manager-only)', function (): void {
    $op = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $device = MobileDevice::query()->create([
        'tenant_id' => $this->tenant->id,
        'operator_id' => $op->id,
        'platform' => 'ios',
        'device_name' => 'Test Phone',
        'app_version' => '1.0.0',
        'paired_at' => now(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Devices::class)
        ->call('revokeMobile', $device->id);

    expect($device->fresh()->revoked_at)->not->toBeNull();
    expect($device->fresh()->revoked_by_operator_id)->toBe($this->manager->id);
});

it('revokeMobile refuses when the current operator is not a manager', function (): void {
    $cleaner = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $device = MobileDevice::query()->create([
        'tenant_id' => $this->tenant->id,
        'operator_id' => $cleaner->id,
        'platform' => 'ios',
        'device_name' => 'Test Phone',
        'app_version' => '1.0.0',
        'paired_at' => now(),
    ]);

    Livewire::actingAs($cleaner, 'web')
        ->test(Devices::class)
        ->call('revokeMobile', $device->id)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    expect($device->fresh()->revoked_at)->toBeNull();
});
