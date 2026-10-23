<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Push\NullDriver;
use App\Services\Push\PushDriver;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/PushTestHelpers.php';

/**
 * Host app phase 5: PUT device/push (one token, one device), GET/PUT
 * device/notifications (prefs + allowed), app=host marking.
 */
beforeEach(function (): void {
    $this->push = fakePush();
    $this->tenant = Tenant::factory()->create(['slug' => 'push-reg']);
    $this->tenant->makeCurrent();
    $this->property = hostBranch($this->tenant, 'Ikoyi', 1)['property'];
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);
});

afterEach(fn () => Tenant::forgetCurrent());

/** The device row behind the bearer token just minted by hostHeaders(). */
function lastDevice(): MobileDevice
{
    return MobileDevice::query()->withoutGlobalScope(TenantScope::class)->latest('id')->firstOrFail();
}

it('stores the push token on the current device and marks it as a host device', function (): void {
    $headers = hostHeaders($this->manager);
    $device = lastDevice();
    expect($device->app)->toBeNull();

    $this->putJson(hostUrl($this->tenant, 'device/push'), ['token' => 'tok-1', 'platform' => 'ios'], $headers + hostWriteKey())
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'push' => ['registered' => true, 'platform' => 'ios', 'push_available' => true]]);

    $device->refresh();
    expect($device->push_token)->toBe('tok-1')
        ->and($device->push_platform)->toBe('ios')
        ->and($device->app)->toBe('host')
        ->and($device->push_token_updated_at)->not->toBeNull();
});

it('clears the token with null and needs an idempotency key', function (): void {
    $headers = hostHeaders($this->manager);
    $device = lastDevice();
    $device->forceFill(['push_token' => 'tok-1', 'push_platform' => 'android'])->save();

    $this->putJson(hostUrl($this->tenant, 'device/push'), ['token' => null, 'platform' => 'android'], $headers)
        ->assertStatus(400);

    $this->putJson(hostUrl($this->tenant, 'device/push'), ['token' => null, 'platform' => 'android'], $headers + hostWriteKey())
        ->assertOk()
        ->assertJsonPath('push.registered', false);
    expect($device->fresh()->push_token)->toBeNull()->and($device->fresh()->push_platform)->toBeNull();

    $this->putJson(hostUrl($this->tenant, 'device/push'), ['token' => 'x', 'platform' => 'web'], $headers + hostWriteKey())
        ->assertStatus(422);
});

it('keeps one token on one device, across tenants too', function (): void {
    $old = pushDevice($this->frontdesk, 'shared-token');
    $other = Tenant::factory()->create(['slug' => 'push-reg-2']);
    $other->makeCurrent();
    $otherOp = Operator::factory()->for($other)->create(['role' => OperatorRole::Manager]);
    $foreign = pushDevice($otherOp, 'shared-token');
    $untouched = pushDevice($otherOp, 'another-token');
    $this->tenant->makeCurrent();

    $headers = hostHeaders($this->manager);
    $device = lastDevice();
    $this->putJson(hostUrl($this->tenant, 'device/push'), ['token' => 'shared-token', 'platform' => 'android'], $headers + hostWriteKey())
        ->assertOk();

    $all = fn (MobileDevice $d) => MobileDevice::query()->withoutGlobalScope(TenantScope::class)->find($d->id);
    expect($device->fresh()->push_token)->toBe('shared-token')
        ->and($all($old)->push_token)->toBeNull()
        ->and($all($foreign)->push_token)->toBeNull()
        ->and($all($untouched)->push_token)->toBe('another-token');
});

it('returns prefs (all on by default) and what an owner may receive', function (): void {
    $this->getJson(hostUrl($this->tenant, 'device/notifications'), hostHeaders($this->manager))
        ->assertOk()
        ->assertExactJson(['data' => [
            'push_available' => true,
            'prefs' => ['bookings' => true, 'payments' => true, 'approvals' => true, 'guest_requests' => true, 'daily_summary' => true, 'low_stock' => true],
            'allowed' => ['bookings' => true, 'payments' => true, 'approvals' => true, 'guest_requests' => true, 'daily_summary' => true, 'low_stock' => true],
        ]]);
    expect(lastDevice()->app)->toBe('host');
});

it('hides owner-only and stock kinds from front desk', function (): void {
    $this->getJson(hostUrl($this->tenant, 'device/notifications'), hostHeaders($this->frontdesk))
        ->assertOk()
        ->assertJsonPath('data.allowed', [
            'bookings' => true, 'payments' => false, 'approvals' => true,
            'guest_requests' => true, 'daily_summary' => false, 'low_stock' => false,
        ]);

    // finance.view makes front desk an owner.
    $this->frontdesk->forceFill(['permissions' => ['finance.view']])->save();
    $this->getJson(hostUrl($this->tenant, 'device/notifications'), hostHeaders($this->frontdesk))
        ->assertJsonPath('data.allowed.payments', true)
        ->assertJsonPath('data.allowed.daily_summary', true);
});

it('updates prefs partially and keeps them per device', function (): void {
    $headers = hostHeaders($this->manager);
    $device = lastDevice();
    $second = hostHeaders($this->manager);

    $this->putJson(hostUrl($this->tenant, 'device/notifications'), ['prefs' => ['payments' => false, 'low_stock' => false]], $headers + hostWriteKey())
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.prefs', [
            'bookings' => true, 'payments' => false, 'approvals' => true,
            'guest_requests' => true, 'daily_summary' => true, 'low_stock' => false,
        ])
        ->assertJsonPath('data.allowed.payments', true);

    $this->putJson(hostUrl($this->tenant, 'device/notifications'), ['prefs' => ['payments' => true]], $headers + hostWriteKey())
        ->assertJsonPath('data.prefs.payments', true)
        ->assertJsonPath('data.prefs.low_stock', false);
    expect($device->fresh()->push_prefs['low_stock'])->toBeFalse();

    // The other phone is untouched (fresh guard: the test app reuses it).
    app('auth')->forgetGuards();
    $this->getJson(hostUrl($this->tenant, 'device/notifications'), $second)
        ->assertJsonPath('data.prefs.low_stock', true);

    app('auth')->forgetGuards();
    $this->putJson(hostUrl($this->tenant, 'device/notifications'), ['prefs' => ['nope' => true]], $headers + hostWriteKey())
        ->assertStatus(422)->assertJsonPath('error', 'unknown_pref');
    $this->putJson(hostUrl($this->tenant, 'device/notifications'), ['prefs' => ['payments' => 'maybe']], $headers + hostWriteKey())
        ->assertStatus(422);
});

it('reports push_available false when push is not configured', function (): void {
    app()->instance(PushDriver::class, new NullDriver);

    $this->getJson(hostUrl($this->tenant, 'device/notifications'), hostHeaders($this->manager))
        ->assertJsonPath('data.push_available', false);
});

it('marks the device app=host when the host app signs in with X-Keverna-App', function (): void {
    Tenant::forgetCurrent();
    $identity = IdentityAccount::query()->create(['email' => 'boss@example.com', 'password' => Hash::make('secret-password-1'), 'name' => 'Boss']);
    $this->manager->forceFill(['email' => 'boss@example.com'])->save();
    IdentityOperatorLink::query()->create(['identity_account_id' => $identity->id, 'tenant_id' => $this->tenant->id, 'operator_id' => $this->manager->id]);
    $url = 'http://'.config('keverna.central_domain').'/api/mobile/v1/login';
    $body = ['email' => 'boss@example.com', 'password' => 'secret-password-1', 'platform' => 'android', 'device_name' => 'Pixel', 'app_version' => '1.0.0'];

    $this->postJson($url, $body + ['push_token' => 'login-tok'], ['X-Keverna-App' => 'host'])->assertOk();
    $host = lastDevice();
    expect($host->app)->toBe('host')->and($host->push_token)->toBe('login-tok')->and($host->push_platform)->toBe('android');

    $this->postJson($url, $body)->assertOk();
    expect(lastDevice()->app)->toBeNull();
});
