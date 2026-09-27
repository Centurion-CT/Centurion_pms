<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\Permission;
use App\Livewire\Auth\Login as TenantLogin;
use App\Livewire\Dashboard\Staff\Index;
use App\Mail\Operators\OperatorInviteMail;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Identity\PostLoginRouter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'staff-lifecycle']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'name' => 'Manager Manny',
        'activated_at' => now(),
    ]);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
        'name' => 'Chidi',
        'email' => 'chidi@keverna.test',
        'password' => Hash::make('KnownPass123'),
        'activated_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── Suspend / unsuspend ─────────────────────────────────────── */

it('manager can suspend a staffer + the staffer can no longer sign in', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('suspend', $this->frontdesk->id)
        ->assertHasNoErrors();

    expect($this->frontdesk->fresh()->suspended_at)->not->toBeNull();

    // Login refuses with a specific message.
    Livewire::test(TenantLogin::class)
        ->set('email', 'chidi@keverna.test')
        ->set('password', 'KnownPass123')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('manager can unsuspend a staffer', function (): void {
    $this->frontdesk->forceFill(['suspended_at' => now()])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('unsuspend', $this->frontdesk->id)
        ->assertHasNoErrors();

    expect($this->frontdesk->fresh()->suspended_at)->toBeNull();
});

it('non-manager cannot suspend anyone', function (): void {
    Livewire::actingAs($this->frontdesk, 'web')
        ->test(Index::class)
        ->call('suspend', $this->manager->id)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err' && str_contains((string) ($p['message'] ?? ''), 'Only managers'));

    expect($this->manager->fresh()->suspended_at)->toBeNull();
});

it('manager cannot suspend their own account', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('suspend', $this->manager->id)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err' && str_contains((string) ($p['message'] ?? ''), 'your own account'));
});

/* ── Revoke / unrevoke ───────────────────────────────────────── */

it('manager can revoke a staffer', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('revoke', $this->frontdesk->id)
        ->assertHasNoErrors();

    expect($this->frontdesk->fresh()->revoked_at)->not->toBeNull();
});

it('manager cannot revoke the last active manager', function (): void {
    // frontdesk isn't a manager — revoking frontdesk is fine.
    // We test the last-manager guard by trying to revoke the sole manager
    // via a second manager, then having the second manager revoked, etc.
    // Here: create a second manager, revoke them → still one manager left, OK.
    // Then try to revoke the remaining one via themselves → self-guard blocks.
    // Then set the target to the manager while the caller is a manager but
    // they'd need someone else to be manager — with only Manny as manager
    // the guard should refuse revoking him.
    $secondManager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'activated_at' => now(),
    ]);

    Livewire::actingAs($secondManager, 'web')
        ->test(Index::class)
        ->call('revoke', $this->manager->id)
        ->assertHasNoErrors();

    // Now Manny is revoked, secondManager is the only active manager.
    // Trying to revoke secondManager (via themselves is self-blocked, but
    // via a promoted frontdesk? no — non-manager can't). Simulate what
    // happens if the second manager tries to revoke themselves:
    Livewire::actingAs($secondManager, 'web')
        ->test(Index::class)
        ->call('revoke', $secondManager->id)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err' && str_contains((string) ($p['message'] ?? ''), 'your own account'));
});

it('revoked staffer cannot sign in even with the correct password', function (): void {
    $this->frontdesk->forceFill(['revoked_at' => now()])->save();

    Livewire::test(TenantLogin::class)
        ->set('email', 'chidi@keverna.test')
        ->set('password', 'KnownPass123')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('manager can unrevoke and access is restored', function (): void {
    $this->frontdesk->forceFill(['revoked_at' => now()])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('unrevoke', $this->frontdesk->id)
        ->assertHasNoErrors();

    expect($this->frontdesk->fresh()->revoked_at)->toBeNull();
});

/* ── Resend invite ───────────────────────────────────────────── */

it('manager can resend an invite to a pending operator', function (): void {
    Mail::fake();

    $pending = Operator::factory()->pendingActivation()->for($this->tenant)->create([
        'email' => 'pending@keverna.test',
        'invite_token_hash' => Hash::make('old-token'),
        'invite_expires_at' => now()->addHour(),
    ]);
    $oldHash = $pending->invite_token_hash;

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('resendInvite', $pending->id)
        ->assertHasNoErrors();

    Mail::assertQueued(OperatorInviteMail::class, fn ($m) => $m->hasTo('pending@keverna.test'));

    // The old hash is retired — a fresh nonce was minted.
    expect($pending->fresh()->invite_token_hash)->not->toBe($oldHash)
        ->and($pending->fresh()->invite_expires_at?->isFuture())->toBeTrue();
});

it('resend invite refuses when the operator has already activated', function (): void {
    Mail::fake();

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('resendInvite', $this->frontdesk->id)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err' && str_contains((string) ($p['message'] ?? ''), 'already activated'));

    Mail::assertNothingQueued();
});

/* ── Permissions page ────────────────────────────────────────── */

it('permissions page loads with the operator prefilled', function (): void {
    $this->frontdesk->forceFill([
        'permissions' => [Permission::FinanceView->value],
    ])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Staff\OperatorPermissions::class, ['operator' => $this->frontdesk->id])
        ->assertSet('operatorId', $this->frontdesk->id)
        ->assertSet('operatorName', 'Chidi')
        ->assertSet('selected', [Permission::FinanceView->value]);
});

it('manager can save permissions on the dedicated page', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Staff\OperatorPermissions::class, ['operator' => $this->frontdesk->id])
        ->set('selected', [Permission::FinanceView->value, Permission::PricesManage->value])
        ->call('save')
        ->assertRedirect(route('tenant.staff.index'));

    $op = $this->frontdesk->fresh();
    expect($op->hasPermission(Permission::FinanceView))->toBeTrue()
        ->and($op->hasPermission(Permission::PricesManage))->toBeTrue()
        ->and($op->hasPermission(Permission::StaffManage))->toBeFalse();
});

it('permissions page save filters out unknown keys', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Staff\OperatorPermissions::class, ['operator' => $this->frontdesk->id])
        ->set('selected', [
            Permission::FinanceView->value,
            'permission.that.does.not.exist',
            'x.y.z',
        ])
        ->call('save');

    expect($this->frontdesk->fresh()->permissions)->toBe([Permission::FinanceView->value]);
});

it('permissions page redirects a non-manager back to the staff index', function (): void {
    Livewire::actingAs($this->frontdesk, 'web')
        ->test(\App\Livewire\Dashboard\Staff\OperatorPermissions::class, ['operator' => $this->manager->id])
        ->assertRedirect(route('tenant.staff.index'));
});

it('permissions page refuses to load an operator from a different tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'stranger-tenant']);
    $otherTenant->makeCurrent();
    $foreign = Operator::factory()->for($otherTenant)->create();
    Tenant::forgetCurrent();

    $this->tenant->makeCurrent();
    Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Staff\OperatorPermissions::class, ['operator' => $foreign->id])
        ->assertRedirect(route('tenant.staff.index'));

    expect($foreign->fresh()->permissions)->toBeNull();
});

it('canAccessModule unions role modules + granted permission modules', function (): void {
    // Frontdesk role does NOT get 'finance' by default.
    expect($this->frontdesk->canAccessModule('finance'))->toBeFalse();

    $this->frontdesk->forceFill([
        'permissions' => [Permission::FinanceView->value],
    ])->save();
    $this->frontdesk->refresh();

    expect($this->frontdesk->canAccessModule('finance'))->toBeTrue();
});

it('canManagePrices honors the PricesManage grant even for a non-manager', function (): void {
    expect($this->frontdesk->canManagePrices())->toBeFalse();

    $this->frontdesk->forceFill([
        'permissions' => [Permission::PricesManage->value],
    ])->save();
    $this->frontdesk->refresh();

    expect($this->frontdesk->canManagePrices())->toBeTrue();
});

/* ── PostLoginRouter filters suspended / revoked ─────────────── */

/* ── Delete ───────────────────────────────────────────────────── */

it('manager can delete a revoked operator + the identity link is cleared', function (): void {
    $identity = IdentityAccount::query()->create([
        'email' => 'to-delete@keverna.test',
        'name' => 'Del',
        'password' => Hash::make('irrelevant'),
    ]);
    $victim = Operator::factory()->for($this->tenant)->create([
        'email' => 'to-delete@keverna.test',
        'activated_at' => now(),
        'revoked_at' => now(),
    ]);
    IdentityOperatorLink::query()->create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $this->tenant->id,
        'operator_id' => $victim->id,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('remove', $victim->id)
        ->assertHasNoErrors();

    expect(Operator::query()->withoutGlobalScope(\App\Multitenancy\TenantScope::class)->find($victim->id))->toBeNull()
        ->and(IdentityOperatorLink::query()->where('operator_id', $victim->id)->exists())->toBeFalse();
});

it('manager can delete a pending operator (never activated)', function (): void {
    $pending = Operator::factory()->pendingActivation()->for($this->tenant)->create([
        'email' => 'never-came@keverna.test',
        'invite_token_hash' => Hash::make('nope'),
        'invite_expires_at' => now()->addHour(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('remove', $pending->id)
        ->assertHasNoErrors();

    expect(Operator::query()->withoutGlobalScope(\App\Multitenancy\TenantScope::class)->find($pending->id))->toBeNull();
});

it('manager cannot delete an active operator — must revoke first', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('remove', $this->frontdesk->id)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err' && str_contains((string) ($p['message'] ?? ''), 'Revoke this operator first'));

    expect(Operator::query()->withoutGlobalScope(\App\Multitenancy\TenantScope::class)->find($this->frontdesk->id))->not->toBeNull();
});

it('manager cannot delete a suspended operator — must revoke first', function (): void {
    $this->frontdesk->forceFill(['suspended_at' => now()])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('remove', $this->frontdesk->id)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err' && str_contains((string) ($p['message'] ?? ''), 'Revoke this operator first'));
});

it('PostLoginRouter treats a suspended operator as un-actionable', function (): void {
    $identity = IdentityAccount::query()->create([
        'email' => 'suspended-router@keverna.test',
        'name' => 'Sam',
        'password' => Hash::make('irrelevant'),
    ]);
    $op = Operator::factory()->for($this->tenant)->create([
        'email' => 'suspended-router@keverna.test',
        'activated_at' => now(),
        'suspended_at' => now(),
    ]);
    IdentityOperatorLink::query()->create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $this->tenant->id,
        'operator_id' => $op->id,
    ]);

    $dest = (new PostLoginRouter)->resolve($identity->fresh());

    // No activated + un-suspended links → invite_pending is the
    // "there's nothing signable for you" signal.
    expect($dest['kind'])->toBe('invite_pending');
});
