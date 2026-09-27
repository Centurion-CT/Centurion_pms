<?php

declare(strict_types=1);

use App\Livewire\Central\ForgotPassword;
use App\Livewire\Central\ResetPassword;
use App\Models\IdentityAccount;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

/**
 * Central-domain forgot / reset flow. Backs the "Forgot?" link on
 * /login and the reset-link email a visitor receives from it.
 *
 * IdentityAccount is on the `identities` password broker with its
 * own reset-token table.
 */

beforeEach(function (): void {
    Notification::fake();
});

it('sendResetLink dispatches a ResetPassword notification for a known identity', function (): void {
    $identity = IdentityAccount::query()->create([
        'name' => 'Ops User',
        'email' => 'ops@example.test',
        'password' => Hash::make('OldPassword123'),
    ]);

    Livewire::test(ForgotPassword::class)
        ->set('email', 'ops@example.test')
        ->call('sendResetLink')
        ->assertSet('sent', true);

    Notification::assertSentTo($identity, ResetPasswordNotification::class);
});

it('sendResetLink still shows the "check your inbox" state for an unknown email (no enumeration)', function (): void {
    Livewire::test(ForgotPassword::class)
        ->set('email', 'ghost@example.test')
        ->call('sendResetLink')
        ->assertSet('sent', true);

    // No notification actually sent since the address isn't on file, but
    // the UI can't reveal that to the visitor.
    Notification::assertNothingSent();
});

it('sendResetLink rejects a malformed email with a validation error', function (): void {
    Livewire::test(ForgotPassword::class)
        ->set('email', 'not-an-email')
        ->call('sendResetLink')
        ->assertHasErrors(['email'])
        ->assertSet('sent', false);
});

it('resetPassword updates the identity password on a valid token', function (): void {
    $identity = IdentityAccount::query()->create([
        'name' => 'Ops User',
        'email' => 'ops@example.test',
        'password' => Hash::make('OldPassword123'),
    ]);

    $token = Password::broker('identities')->createToken($identity);

    Livewire::test(ResetPassword::class, ['token' => $token])
        ->set('email', 'ops@example.test')
        ->set('password', 'BrandNew456')
        ->set('password_confirmation', 'BrandNew456')
        ->call('resetPassword')
        ->assertRedirect(route('login'));

    expect(Hash::check('BrandNew456', $identity->fresh()->password))->toBeTrue();
});

it('resetPassword refuses a stale / invalid token', function (): void {
    IdentityAccount::query()->create([
        'name' => 'Ops User',
        'email' => 'ops@example.test',
        'password' => Hash::make('OldPassword123'),
    ]);

    Livewire::test(ResetPassword::class, ['token' => 'not-a-real-token'])
        ->set('email', 'ops@example.test')
        ->set('password', 'BrandNew456')
        ->set('password_confirmation', 'BrandNew456')
        ->call('resetPassword')
        ->assertHasErrors(['email']);
});

it('resetPassword refuses when the confirmation does not match', function (): void {
    $identity = IdentityAccount::query()->create([
        'name' => 'Ops User',
        'email' => 'ops@example.test',
        'password' => Hash::make('OldPassword123'),
    ]);

    $token = Password::broker('identities')->createToken($identity);

    Livewire::test(ResetPassword::class, ['token' => $token])
        ->set('email', 'ops@example.test')
        ->set('password', 'BrandNew456')
        ->set('password_confirmation', 'Different789')
        ->call('resetPassword')
        ->assertHasErrors(['password']);

    // Password unchanged.
    expect(Hash::check('OldPassword123', $identity->fresh()->password))->toBeTrue();
});
