<?php

declare(strict_types=1);

namespace App\Livewire\Central;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Central-domain "forgot password" flow (docs/unified-login.md).
 * Backs /password/forgot on keverna.com and sends a reset link
 * scoped to the `identities` broker, so a single IdentityAccount
 * can reset once and re-use the new password across every tenant
 * they operate in.
 *
 * Response copy is intentionally non-enumerating: whether or not
 * we found a matching identity, the operator sees "if the email
 * matches an account, a link is on its way." Actual errors that
 * can't be silenced (rate-limit) still surface.
 */
#[Layout('layouts.auth')]
class ForgotPassword extends Component
{
    #[Validate('required|email|max:180')]
    public string $email = '';

    /** Set after a successful send so the view can flip to the
     *  "check your inbox" state. */
    public bool $sent = false;

    public function sendResetLink(): void
    {
        $this->validate();

        // Rate-limit by email + IP so a scripter can't burn through
        // sends and exhaust the mail queue.
        $throttleKey = Str::lower(trim($this->email)).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts('kv:forgot:'.$throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in a minute.',
            ]);
        }
        RateLimiter::hit('kv:forgot:'.$throttleKey, 60);

        // Password::broker('identities') → identity_accounts provider →
        // sends `Illuminate\Auth\Notifications\ResetPassword` on the
        // Notifiable trait bound to IdentityAccount.
        $status = Password::broker('identities')->sendResetLink([
            'email' => Str::lower(trim($this->email)),
        ]);

        // We deliberately flip to `sent` regardless of what the broker
        // returned — showing "we couldn't find your email" would turn
        // this form into an identity-enumeration oracle. The broker's
        // own throttle catches abusive volume server-side.
        $this->sent = true;

        // Bury the actual response into the session so admins can grep
        // it out of the log if a legit user reports non-delivery.
        logger()->info('identity.forgot_password.attempt', [
            'email_hash' => hash('sha256', Str::lower(trim($this->email))),
            'status' => $status,
            'ip' => request()->ip(),
        ]);
    }

    public function render(): View
    {
        return view('livewire.central.forgot-password');
    }
}
