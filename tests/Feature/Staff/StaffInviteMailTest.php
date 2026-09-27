<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Http\Controllers\Tenant\OperatorInviteClaimController;
use App\Livewire\Auth\Login as TenantLogin;
use App\Livewire\Dashboard\Staff\Index;
use App\Livewire\Tenant\Auth\ActivateInvite;
use App\Mail\Operators\OperatorInviteMail;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'invite-mail', 'name' => 'Citistay']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'name' => 'Manager Manny',
        'activated_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('invite dispatches OperatorInviteMail with a magic link (no temp password)', function (): void {
    Mail::fake();

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->set('inviteName', 'Chika Okafor')
        ->set('inviteEmail', 'chika@keverna.test')
        ->set('inviteRole', OperatorRole::Frontdesk->value)
        ->call('invite')
        ->assertHasNoErrors();

    Mail::assertQueued(OperatorInviteMail::class, function (OperatorInviteMail $m) {
        return $m->hasTo('chika@keverna.test')
            && $m->tenant->id === $this->tenant->id
            && $m->inviterName === 'Manager Manny'
            && $m->roleLabel === OperatorRole::Frontdesk->label()
            && str_contains($m->magicLink, '/operator-invite/claim/')
            && str_contains($m->magicLink, 'signature=');
    });
});

it('invite creates an un-activated operator + identity + link', function (): void {
    Mail::fake();

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->set('inviteName', 'Nneka')
        ->set('inviteEmail', 'nneka@keverna.test')
        ->set('inviteRole', OperatorRole::Frontdesk->value)
        ->call('invite');

    $op = Operator::query()->where('email', 'nneka@keverna.test')->firstOrFail();
    expect($op->activated_at)->toBeNull()
        ->and($op->invite_token_hash)->not->toBeNull()
        ->and($op->invite_expires_at?->isFuture())->toBeTrue();

    $identity = IdentityAccount::query()->where('email', 'nneka@keverna.test')->firstOrFail();
    expect($identity->name)->toBe('Nneka');

    $link = IdentityOperatorLink::query()
        ->where('identity_account_id', $identity->id)
        ->where('tenant_id', $this->tenant->id)
        ->firstOrFail();
    expect((int) $link->operator_id)->toBe($op->id);
});

it('magic link stores a session marker and redirects to /invite/activate, no login', function (): void {
    $token = 'raw-invite-token';
    $op = Operator::factory()->pendingActivation()->for($this->tenant)->create([
        'email' => 'ada@keverna.test',
        'invite_token_hash' => Hash::make($token),
        'invite_expires_at' => Carbon::now()->addHours(2),
    ]);

    $url = URL::temporarySignedRoute(
        'tenant.operator-invite.claim',
        Carbon::now()->addHours(2),
        ['operator' => $op->id, 'token' => $token],
    );

    $this->get($url)->assertRedirect(route('tenant.invite.activate'));

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(session()->get(OperatorInviteClaimController::SESSION_KEY))->toBe($op->id);

    // Token STAYS valid across the claim — activation is what
    // consumes it. This keeps the link idempotent so the invitee
    // can bounce between devices or reload before finishing.
    $op->refresh();
    expect($op->invite_token_hash)->not->toBeNull()
        ->and($op->activated_at)->toBeNull();
});

it('claim controller re-opens the activation page when clicked twice pre-activation', function (): void {
    $token = 'stay-valid';
    $op = Operator::factory()->pendingActivation()->for($this->tenant)->create([
        'invite_token_hash' => Hash::make($token),
        'invite_expires_at' => Carbon::now()->addHours(2),
    ]);

    $url = URL::temporarySignedRoute(
        'tenant.operator-invite.claim',
        Carbon::now()->addHours(2),
        ['operator' => $op->id, 'token' => $token],
    );

    $this->get($url)->assertRedirect(route('tenant.invite.activate'));
    session()->flush();

    // Second click BEFORE activation → still lands on activate page,
    // NOT the "already used" login redirect. Token still stamped.
    $this->get($url)->assertRedirect(route('tenant.invite.activate'));
    expect($op->fresh()->invite_token_hash)->not->toBeNull();
});

it('claim controller drops any existing web session before redirecting', function (): void {
    $existing = Operator::factory()->for($this->tenant)->create([
        'email' => 'incumbent@keverna.test',
    ]);
    Auth::guard('web')->login($existing);
    expect(Auth::guard('web')->check())->toBeTrue();

    $token = 'fresh-invitee';
    $op = Operator::factory()->pendingActivation()->for($this->tenant)->create([
        'email' => 'invitee@keverna.test',
        'invite_token_hash' => Hash::make($token),
        'invite_expires_at' => Carbon::now()->addHours(2),
    ]);

    $url = URL::temporarySignedRoute(
        'tenant.operator-invite.claim',
        Carbon::now()->addHours(2),
        ['operator' => $op->id, 'token' => $token],
    );

    $this->get($url)->assertRedirect(route('tenant.invite.activate'));

    // Incumbent got logged out so the activate page renders on a
    // clean guest layout, not the incumbent's dashboard sidebar.
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('claim controller bounces to /login when the operator is already activated', function (): void {
    $token = 'was-used';
    $op = Operator::factory()->for($this->tenant)->create([
        'activated_at' => now(),
        'invite_token_hash' => Hash::make($token),
        'invite_expires_at' => Carbon::now()->addHours(2),
    ]);

    $url = URL::temporarySignedRoute(
        'tenant.operator-invite.claim',
        Carbon::now()->addHours(2),
        ['operator' => $op->id, 'token' => $token],
    );

    $this->get($url)->assertRedirect(route('tenant.login'));
    expect(session()->has(OperatorInviteClaimController::SESSION_KEY))->toBeFalse();
});

it('rejects a magic link with a bad token', function (): void {
    $op = Operator::factory()->for($this->tenant)->create([
        'invite_token_hash' => Hash::make('the-real-one'),
        'invite_expires_at' => Carbon::now()->addHours(2),
    ]);

    $url = URL::temporarySignedRoute(
        'tenant.operator-invite.claim',
        Carbon::now()->addHours(2),
        ['operator' => $op->id, 'token' => 'wrong-guess'],
    );

    $this->get($url)->assertRedirect(route('tenant.login'));
    expect(session()->has(OperatorInviteClaimController::SESSION_KEY))->toBeFalse();
});

it('rejects a magic link once activation completes (token cleared by ActivateInvite)', function (): void {
    // ActivateInvite is what clears the token on save. Simulate the
    // post-activation state directly: activated_at stamped, hash
    // nulled. A late click should bounce to /login.
    $op = Operator::factory()->for($this->tenant)->create([
        'invite_token_hash' => null,
        'invite_expires_at' => null,
        'activated_at' => now(),
    ]);

    $url = URL::temporarySignedRoute(
        'tenant.operator-invite.claim',
        Carbon::now()->addHours(2),
        ['operator' => $op->id, 'token' => 'anything'],
    );

    $this->get($url)->assertRedirect(route('tenant.login'));
    expect(session()->has(OperatorInviteClaimController::SESSION_KEY))->toBeFalse();
});

it('activate page redirects to login when no pending id in session', function (): void {
    $this->get(route('tenant.invite.activate'))->assertRedirect(route('tenant.login'));
});

it('activate saves password on operator + identity, stamps activated_at, logs in', function (): void {
    // Simulate a claimed-but-not-activated state (matches what
    // Staff/Index::invite produces after the magic link is clicked).
    $op = Operator::factory()->pendingActivation()->for($this->tenant)->create([
        'email' => 'zainab@keverna.test',
        'name' => 'Zainab',
        'password' => Hash::make('sentinel-unusable'),
        'email_verified_at' => null,
        'phone_verified_at' => null,
        'phone_verification_skipped_at' => null,
    ]);
    $identity = IdentityAccount::query()->create([
        'email' => 'zainab@keverna.test',
        'name' => 'Zainab',
        'password' => Hash::make('sentinel-unusable'),
    ]);
    IdentityOperatorLink::query()->create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $this->tenant->id,
        'operator_id' => $op->id,
    ]);
    session()->put(OperatorInviteClaimController::SESSION_KEY, $op->id);

    Livewire\Livewire::test(ActivateInvite::class)
        ->set('password', 'BrandNew99abcd')
        ->set('password_confirmation', 'BrandNew99abcd')
        ->call('activate')
        ->assertHasNoErrors();

    $op->refresh();
    $identity->refresh();

    // Passwords now match the picked one on BOTH sides.
    expect(Hash::check('BrandNew99abcd', (string) $op->password))->toBeTrue()
        ->and(Hash::check('BrandNew99abcd', (string) $identity->password))->toBeTrue()
        ->and($op->activated_at)->not->toBeNull()
        ->and($op->last_login_at)->not->toBeNull()
        // Magic-link click counts as email verification; invitees
        // skip the phone step so they land straight on the dashboard,
        // not on the onboarding email/phone screens.
        ->and($op->email_verified_at)->not->toBeNull()
        ->and($op->phone_verification_skipped_at)->not->toBeNull()
        ->and($op->hasResolvedPhoneStep())->toBeTrue()
        ->and(Auth::guard('web')->id())->toBe($op->id)
        ->and(session()->has(OperatorInviteClaimController::SESSION_KEY))->toBeFalse();
});

it('tenant Login refuses an un-activated operator even with the correct password', function (): void {
    Operator::factory()->for($this->tenant)->create([
        'email' => 'pending@keverna.test',
        'password' => Hash::make('KnownPass123'),
        'activated_at' => null,
    ]);

    Livewire\Livewire::test(TenantLogin::class)
        ->set('email', 'pending@keverna.test')
        ->set('password', 'KnownPass123')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('tenant Login lets an activated operator sign in', function (): void {
    Operator::factory()->for($this->tenant)->create([
        'email' => 'active@keverna.test',
        'password' => Hash::make('KnownPass123'),
        'activated_at' => now(),
    ]);

    Livewire\Livewire::test(TenantLogin::class)
        ->set('email', 'active@keverna.test')
        ->set('password', 'KnownPass123')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Auth::guard('web')->check())->toBeTrue();
});

it('PostLoginRouter skips un-activated operator links', function (): void {
    $router = app(\App\Services\Identity\PostLoginRouter::class);

    // Identity linked to a single UN-activated operator.
    $identity = IdentityAccount::query()->create([
        'email' => 'pending-router@keverna.test',
        'name' => 'Pending',
        'password' => Hash::make('doesnt-matter'),
    ]);
    $op = Operator::factory()->for($this->tenant)->create([
        'email' => 'pending-router@keverna.test',
        'activated_at' => null,
    ]);
    IdentityOperatorLink::query()->create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $this->tenant->id,
        'operator_id' => $op->id,
    ]);

    $dest = $router->resolve($identity->fresh());
    expect($dest['kind'])->toBe('invite_pending');

    // Now activate the operator — router hands off cleanly.
    $op->forceFill(['activated_at' => now()])->save();
    $dest = $router->resolve($identity->fresh());
    expect($dest['kind'])->toBe('handoff');
});
