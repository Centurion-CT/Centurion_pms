<?php

declare(strict_types=1);

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'reset-tests']);
    $this->tenant->makeCurrent();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('sends a password reset notification for a known email', function (): void {
    Notification::fake();

    $operator = Operator::factory()->for($this->tenant)->create([
        'email' => 'test@x.test',
    ]);

    Livewire::test(ForgotPassword::class)
        ->set('email', 'test@x.test')
        ->call('sendResetLink')
        ->assertHasNoErrors();

    Notification::assertSentTo($operator, ResetPasswordNotification::class);
});

it('errors when the email is not registered', function (): void {
    Notification::fake();

    Livewire::test(ForgotPassword::class)
        ->set('email', 'ghost@x.test')
        ->call('sendResetLink')
        ->assertHasErrors(['email']);

    Notification::assertNothingSent();
});

it('rejects an invalid email format', function (): void {
    Livewire::test(ForgotPassword::class)
        ->set('email', 'not-an-email')
        ->call('sendResetLink')
        ->assertHasErrors(['email']);
});

it('resets the password with a valid token', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create([
        'email' => 'test@x.test',
        'password' => Hash::make('old-pass-9'),
    ]);

    $token = Password::broker('operators')->createToken($operator);

    Livewire::test(ResetPassword::class, ['token' => $token])
        ->set('email', 'test@x.test')
        ->set('password', 'new-pass-99')
        ->set('password_confirmation', 'new-pass-99')
        ->call('resetPassword')
        ->assertRedirect(route('tenant.login'));

    expect(Hash::check('new-pass-99', $operator->fresh()->password))->toBeTrue();
    expect(Hash::check('old-pass-9', $operator->fresh()->password))->toBeFalse();
});

it('rejects a tampered or expired token', function (): void {
    Operator::factory()->for($this->tenant)->create(['email' => 'test@x.test']);

    Livewire::test(ResetPassword::class, ['token' => 'fake-token-not-real'])
        ->set('email', 'test@x.test')
        ->set('password', 'new-pass-99')
        ->set('password_confirmation', 'new-pass-99')
        ->call('resetPassword')
        ->assertHasErrors(['email']);
});

it('rejects a weak new password', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create(['email' => 'test@x.test']);
    $token = Password::broker('operators')->createToken($operator);

    Livewire::test(ResetPassword::class, ['token' => $token])
        ->set('email', 'test@x.test')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('resetPassword')
        ->assertHasErrors(['password']);
});
