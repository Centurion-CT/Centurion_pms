<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\Activity;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

require_once __DIR__.'/DeskTestHelpers.php';

/**
 * Keverna Desk phase 1 server (docs/desk-app/PHASE-1.md): desk set up
 * through central login, who's at the desk, PIN unlock into per-operator
 * tokens, lock, revoke, and the scope each token kind is allowed.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-23 09:00:00'));

    $this->tenant = Tenant::factory()->create(['slug' => 'desk-hotel']);
    $this->tenant->makeCurrent();
    $ikoyi = hostBranch($this->tenant, 'Ikoyi', 3, '1');
    $this->ikoyi = $ikoyi['property'];
    $this->rooms = $ikoyi['rooms'];
    $this->abuja = hostBranch($this->tenant, 'Abuja', 1, '9')['property'];

    $mk = fn (string $name, OperatorRole $role, ?string $pin, array $extra = []) => Operator::factory()->for($this->tenant)->create(array_merge([
        'name' => $name,
        'role' => $role,
        'staff_pin_hash' => $pin !== null ? Hash::make($pin) : null,
    ], $extra));

    $this->manager = $mk('Chidi Okafor', OperatorRole::Manager, '1111');
    $this->frontdesk = $mk('Ngozi Eze', OperatorRole::Frontdesk, '2222');
    $this->officerAbuja = $mk('Femi Bello', OperatorRole::ReservationOfficer, '3333');
    $this->officerAbuja->branches()->attach($this->abuja->id);
    $this->cleaner = $mk('Bisi Ojo', OperatorRole::HouseCleaner, '4444');
    $this->suspended = $mk('Tolu Ade', OperatorRole::Frontdesk, '5555', ['suspended_at' => now()]);
    $this->noPin = $mk('Ada Obi', OperatorRole::Frontdesk, null);
    $this->frontdesk->forceFill(['current_property_id' => $this->ikoyi->id])->save();

    $this->other = Tenant::factory()->create(['slug' => 'desk-other']);
    $this->other->makeCurrent();
    hostBranch($this->other, 'Other', 1, '5');
    $this->outsider = Operator::factory()->for($this->other)->create([
        'name' => 'Zara Musa', 'role' => OperatorRole::Frontdesk, 'staff_pin_hash' => Hash::make('2222'),
    ]);

    $this->tenant->makeCurrent();
    $desk = makeDesk($this->frontdesk, $this->ikoyi->id);
    $this->desk = $desk['device'];
    $this->deskToken = $desk['token'];
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function deskUnlockAs(object $t, Operator $op, string $pin, ?string $deskToken = null): TestResponse
{
    return $t->postJson(deskUrl($t->tenant, 'unlock'), ['operator_id' => $op->id, 'pin' => $pin], deskBearer($deskToken ?? $t->deskToken, deskKey()));
}

// ── Set up via central login ─────────────────────────────────────

it('sets up a desk through central login with the desk app header, host roles only', function (): void {
    $identity = IdentityAccount::query()->create(['email' => 'ngozi@example.com', 'password' => Hash::make('secret-password-1'), 'name' => 'Ngozi Eze']);
    IdentityOperatorLink::query()->create(['identity_account_id' => $identity->id, 'tenant_id' => $this->tenant->id, 'operator_id' => $this->frontdesk->id]);
    $cleanerIdentity = IdentityAccount::query()->create(['email' => 'bisi@example.com', 'password' => Hash::make('secret-password-1'), 'name' => 'Bisi Ojo']);
    IdentityOperatorLink::query()->create(['identity_account_id' => $cleanerIdentity->id, 'tenant_id' => $this->tenant->id, 'operator_id' => $this->cleaner->id]);
    Tenant::forgetCurrent();

    $body = fn (string $email) => ['email' => $email, 'password' => 'secret-password-1', 'platform' => 'desktop', 'device_name' => 'Front desk PC', 'app_version' => '1.0.0+1'];

    $res = $this->postJson(deskLoginUrl(), $body('ngozi@example.com'), ['X-Keverna-App' => 'desk'])->assertOk();
    $device = MobileDevice::query()->withoutGlobalScopes()->findOrFail($res->json('device.id'));
    expect($device->app)->toBe(MobileDevice::APP_DESK)
        ->and($device->platform)->toBe('desktop');

    // A housekeeper can't set up a desk (but can still sign in to their own app).
    $this->postJson(deskLoginUrl(), $body('bisi@example.com'), ['X-Keverna-App' => 'desk'])
        ->assertForbidden()->assertJsonPath('error', 'role_not_supported');
    $this->postJson(deskLoginUrl(), $body('bisi@example.com'), ['X-Keverna-App' => 'staff'])->assertOk();

    // The new desk token works on desk/session.
    $this->getJson(deskUrl($this->tenant, 'session'), deskBearer($res->json('token')))
        ->assertOk()
        ->assertJsonPath('data.device.id', $device->id)
        ->assertJsonPath('data.set_up_by.id', $this->frontdesk->id);
});

it('only offers tenants where the person holds a host role in the desk picker', function (): void {
    $identity = IdentityAccount::query()->create(['email' => 'multi@example.com', 'password' => Hash::make('secret-password-1'), 'name' => 'Multi']);
    IdentityOperatorLink::query()->create(['identity_account_id' => $identity->id, 'tenant_id' => $this->tenant->id, 'operator_id' => $this->manager->id]);
    $this->other->makeCurrent();
    $cleanerOther = Operator::factory()->for($this->other)->create(['role' => OperatorRole::HouseCleaner]);
    IdentityOperatorLink::query()->create(['identity_account_id' => $identity->id, 'tenant_id' => $this->other->id, 'operator_id' => $cleanerOther->id]);
    Tenant::forgetCurrent();

    $body = ['email' => 'multi@example.com', 'password' => 'secret-password-1', 'platform' => 'desktop', 'device_name' => 'PC', 'app_version' => '1.0.0'];

    // Host app: both tenants -> picker. Desk: only the manager seat -> straight in.
    $this->postJson(deskLoginUrl(), $body, ['X-Keverna-App' => 'host'])->assertStatus(300);
    $this->postJson(deskLoginUrl(), $body, ['X-Keverna-App' => 'desk'])->assertOk()
        ->assertJsonPath('tenant.id', $this->tenant->id);
    $this->postJson(deskLoginUrl(), $body + ['tenant_id' => $this->other->id], ['X-Keverna-App' => 'desk'])
        ->assertForbidden();
});

// ── Session + branch ─────────────────────────────────────────────

it('asks for the desk branch when the set up person has several, then pins it', function (): void {
    $desk = makeDesk($this->manager);

    $this->getJson(deskUrl($this->tenant, 'session'), deskBearer($desk['token']))
        ->assertOk()
        ->assertJsonPath('data.property', null)
        ->assertJsonCount(2, 'data.properties');
    $this->getJson(deskUrl($this->tenant, 'operators'), deskBearer($desk['token']))
        ->assertStatus(409)->assertJsonPath('error', 'desk_branch_required');

    // Another tenant's branch reads as not accessible.
    $this->other->makeCurrent();
    $foreign = Property::query()->first();
    $this->tenant->makeCurrent();
    $this->postJson(deskUrl($this->tenant, 'property'), ['property_id' => $foreign->id], deskBearer($desk['token'], deskKey()))
        ->assertForbidden()->assertJsonPath('error', 'property_not_accessible');

    $this->postJson(deskUrl($this->tenant, 'property'), ['property_id' => $this->abuja->id], deskBearer($desk['token'], deskKey()))
        ->assertOk()->assertJsonPath('property.id', $this->abuja->id);
    expect($desk['device']->fresh()->property_id)->toBe($this->abuja->id);
});

it('picks the branch for a person who only has one', function (): void {
    $desk = makeDesk($this->officerAbuja);

    $this->getJson(deskUrl($this->tenant, 'session'), deskBearer($desk['token']))
        ->assertOk()->assertJsonPath('data.property.id', $this->abuja->id);
    expect($desk['device']->fresh()->property_id)->toBe($this->abuja->id);
});

// ── Who's at the desk ────────────────────────────────────────────

it('lists host-role operators who can work in the desk branch', function (): void {
    $res = $this->getJson(deskUrl($this->tenant, 'operators'), deskBearer($this->deskToken))->assertOk();

    expect(collect($res->json('data'))->pluck('name')->all())->toBe(['Ada Obi', 'Chidi Okafor', 'Ngozi Eze'])
        ->and($res->json('data.1'))->toBe([
            'id' => $this->manager->id, 'name' => 'Chidi Okafor', 'initials' => 'CO', 'role' => 'manager',
            'role_label' => 'Manager', 'has_pin' => true, 'last_unlock_at' => null,
        ])
        ->and($res->json('data.0.has_pin'))->toBeFalse();

    deskUnlockAs($this, $this->manager, '1111')->assertOk();
    $after = $this->getJson(deskUrl($this->tenant, 'operators'), deskBearer($this->deskToken))->assertOk();
    expect($after->json('data.0.id'))->toBe($this->manager->id)
        ->and($after->json('data.0.last_unlock_at'))->toBe('2026-10-23T09:00:00+00:00');
});

// ── Unlock ───────────────────────────────────────────────────────

it('unlocks an operator into their own 12 hour token that works on the host API', function (): void {
    $res = deskUnlockAs($this, $this->manager, '1111')->assertOk();

    expect($res->json())->toMatchArray(['status' => 'ok', 'expires_at' => '2026-10-23T21:00:00+00:00'])
        ->and($res->json('operator'))->toMatchArray(['id' => $this->manager->id, 'name' => 'Chidi Okafor', 'initials' => 'CO', 'role' => 'manager'])
        ->and($res->json('operator.capabilities.owner'))->toBeTrue()
        ->and($res->json('property.id'))->toBe($this->ikoyi->id);

    $token = PersonalAccessToken::findToken($res->json('token'));
    expect($token->name)->toBe('desk-operator-'.$this->desk->id)
        ->and($token->tokenable_id)->toBe($this->manager->id);

    // Acts as the manager, pinned to the desk's branch (the manager's own
    // current branch is Abuja).
    $this->manager->forceFill(['current_property_id' => $this->abuja->id])->save();
    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($res->json('token')))
        ->assertOk()
        ->assertJsonPath('data.operator.id', $this->manager->id)
        ->assertJsonPath('data.property.id', $this->ikoyi->id);
    $this->getJson(hostUrl($this->tenant, 'rooms'), deskBearer($res->json('token'), ['X-Keverna-Property' => (string) $this->ikoyi->id]))->assertOk();
    $this->getJson(hostUrl($this->tenant, 'rooms'), deskBearer($res->json('token'), ['X-Keverna-Property' => (string) $this->abuja->id]))
        ->assertForbidden()->assertJsonPath('error', 'property_not_accessible');

    // Expires after 12 h.
    Carbon::setTestNow(Carbon::parse('2026-10-23 21:00:01'));
    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($res->json('token')))->assertUnauthorized();
});

it('keeps one token per operator per desk and never caches the token for replay', function (): void {
    $key = deskKey();
    $first = $this->postJson(deskUrl($this->tenant, 'unlock'), ['operator_id' => $this->manager->id, 'pin' => '1111'], deskBearer($this->deskToken, $key))->assertOk();
    $second = $this->postJson(deskUrl($this->tenant, 'unlock'), ['operator_id' => $this->manager->id, 'pin' => '1111'], deskBearer($this->deskToken, $key))->assertOk();

    expect($second->headers->has('Idempotent-Replay'))->toBeFalse()
        ->and($second->json('token'))->not->toBe($first->json('token'))
        ->and(PersonalAccessToken::query()->where('name', 'desk-operator-'.$this->desk->id)->count())->toBe(1);
    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($first->json('token')))->assertUnauthorized();

    // Several operators can be unlocked on one desk over a shift.
    $fd = deskUnlockAs($this, $this->frontdesk, '2222')->assertOk();
    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($fd->json('token')))->assertJsonPath('data.operator.id', $this->frontdesk->id);
    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($second->json('token')))->assertJsonPath('data.operator.id', $this->manager->id);
});

it('rejects a wrong PIN and locks that operator out on this desk after 5 tries', function (): void {
    for ($i = 4; $i >= 1; $i--) {
        deskUnlockAs($this, $this->manager, '9999')->assertStatus(422)
            ->assertJsonPath('error', 'wrong_pin')
            ->assertJsonPath('attempts_left', $i);
    }
    deskUnlockAs($this, $this->manager, '9999')->assertStatus(422)->assertJsonPath('attempts_left', 0);

    // Even the right PIN is refused for 15 min.
    deskUnlockAs($this, $this->manager, '1111')->assertStatus(429)->assertJsonPath('error', 'too_many_attempts');

    // Another operator on the same desk, and the same operator on another desk, are unaffected.
    deskUnlockAs($this, $this->frontdesk, '2222')->assertOk();
    $otherDesk = makeDesk($this->frontdesk, $this->ikoyi->id);
    deskUnlockAs($this, $this->manager, '1111', $otherDesk['token'])->assertOk();

    Carbon::setTestNow(now()->addMinutes(16));
    deskUnlockAs($this, $this->manager, '1111')->assertOk();
});

it('caps unlock attempts per desk across operators', function (): void {
    foreach (range(1, 20) as $i) {
        $this->postJson(deskUrl($this->tenant, 'unlock'), ['operator_id' => 900000 + $i, 'pin' => '1111'], deskBearer($this->deskToken, deskKey()))
            ->assertNotFound();
    }
    deskUnlockAs($this, $this->frontdesk, '2222')->assertStatus(429);
});

it('answers the same for an operator of another tenant as for one that does not exist', function (): void {
    $foreign = deskUnlockAs($this, $this->outsider, '2222')->assertNotFound();
    $missing = $this->postJson(deskUrl($this->tenant, 'unlock'), ['operator_id' => 999999, 'pin' => '2222'], deskBearer($this->deskToken, deskKey()))->assertNotFound();

    expect($foreign->json())->toBe($missing->json())
        ->and($foreign->json('error'))->toBe('operator_not_found');

    // Same for people of this tenant who can't use this desk.
    deskUnlockAs($this, $this->cleaner, '4444')->assertNotFound();
    deskUnlockAs($this, $this->suspended, '5555')->assertNotFound();
    deskUnlockAs($this, $this->officerAbuja, '3333')->assertNotFound();
});

it('tells an operator without a PIN where to set one', function (): void {
    deskUnlockAs($this, $this->noPin, '1234')->assertStatus(409)
        ->assertJsonPath('error', 'no_pin')
        ->assertJsonPath('detail', 'No PIN yet. A manager sets it on the Staff page.');
});

// ── Lock + revoke ────────────────────────────────────────────────

it('locks: the operator token stops working', function (): void {
    $token = deskUnlockAs($this, $this->manager, '1111')->json('token');

    $this->postJson(deskUrl($this->tenant, 'lock'), [], deskBearer($token, deskKey()))->assertOk()->assertJsonPath('status', 'ok');
    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($token))->assertUnauthorized();

    // The desk itself stays set up.
    $this->getJson(deskUrl($this->tenant, 'operators'), deskBearer($this->deskToken))->assertOk();
});

it('revoking the desk revokes every operator token issued on it', function (): void {
    $a = deskUnlockAs($this, $this->manager, '1111')->json('token');
    $b = deskUnlockAs($this, $this->frontdesk, '2222')->json('token');

    $this->desk->forceFill(['revoked_at' => now(), 'revoked_by_operator_id' => $this->manager->id])->save();

    expect(PersonalAccessToken::query()->where('name', 'desk-operator-'.$this->desk->id)->count())->toBe(0);
    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($a))->assertUnauthorized();
    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($b))->assertUnauthorized();
    $this->getJson(deskUrl($this->tenant, 'operators'), deskBearer($this->deskToken))
        ->assertUnauthorized()->assertJsonPath('error', 'device_revoked');
});

it('drops an unlocked operator who gets suspended', function (): void {
    $token = deskUnlockAs($this, $this->manager, '1111')->json('token');
    $this->manager->forceFill(['suspended_at' => now()])->save();

    $this->getJson(hostUrl($this->tenant, 'session'), deskBearer($token))->assertUnauthorized();
});

// ── Token scope ──────────────────────────────────────────────────

it('keeps each token kind to its own endpoints', function (): void {
    // The locked desk token can't act.
    $this->getJson(hostUrl($this->tenant, 'today'), deskBearer($this->deskToken))
        ->assertForbidden()->assertJsonPath('error', 'desk_locked');
    $this->getJson('http://desk-hotel.'.config('keverna.central_domain').'/api/mobile/v1/reservations', deskBearer($this->deskToken))
        ->assertForbidden()->assertJsonPath('error', 'desk_locked');
    $this->postJson(deskUrl($this->tenant, 'lock'), [], deskBearer($this->deskToken, deskKey()))->assertForbidden();

    // An operator token can't unlock others, list the desk, log the desk out or switch branch.
    $op = deskUnlockAs($this, $this->manager, '1111')->json('token');
    $this->getJson(deskUrl($this->tenant, 'operators'), deskBearer($op))->assertForbidden();
    $this->postJson(deskUrl($this->tenant, 'unlock'), ['operator_id' => $this->frontdesk->id, 'pin' => '2222'], deskBearer($op, deskKey()))->assertForbidden();
    $this->postJson('http://desk-hotel.'.config('keverna.central_domain').'/api/mobile/v1/logout', [], deskBearer($op, deskKey()))->assertForbidden();
    $this->postJson(hostUrl($this->tenant, "properties/{$this->abuja->id}/switch"), [], deskBearer($op, deskKey()))->assertForbidden();
    expect($this->desk->fresh()->revoked_at)->toBeNull();

    // A phone's device token isn't a desk.
    $this->getJson(deskUrl($this->tenant, 'operators'), hostHeaders($this->frontdesk))
        ->assertForbidden()->assertJsonPath('error', 'not_a_desk');
    $this->postJson(deskUrl($this->tenant, 'unlock'), ['operator_id' => $this->manager->id, 'pin' => '1111'], hostHeaders($this->frontdesk) + deskKey())
        ->assertForbidden()->assertJsonPath('error', 'not_a_desk');
});

it('stops the desk when the person who set it up is suspended', function (): void {
    $this->frontdesk->forceFill(['suspended_at' => now()])->save();

    $this->getJson(deskUrl($this->tenant, 'operators'), deskBearer($this->deskToken))->assertUnauthorized();
});

it('marks activity logged from the desk', function (): void {
    $token = deskUnlockAs($this, $this->manager, '1111')->json('token');
    $room = $this->rooms[0];

    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/status"), ['state' => 'dirty'], deskBearer($token, deskKey()))->assertOk();

    $log = Activity::query()->latest('id')->first();
    expect($log->causer_id)->toBe($this->manager->id)
        ->and($log->properties['via'] ?? null)->toBe('desk')
        ->and($log->properties['desk_device_id'] ?? null)->toBe($this->desk->id);
});
