<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\Operator;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Tenant-side operator login (PRD §2). Runs inside the tenant route group, so
 * the Operator model query is automatically scoped to the resolved tenant -
 * an operator from Tenant A cannot authenticate on Tenant B's subdomain even
 * if email + password match.
 *
 * Rate limiting: 5 attempts per minute per IP+email pair, per PRD §2.
 */
#[Layout('layouts.tenant-auth')]
class Login extends Component
{
    #[Validate('required|email|max:180')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /** Prefill from the "your login links" email (centurionpms.com/login). */
    public function mount(): void
    {
        $email = request()->query('email');
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->email = $email;
        }
    }

    public function authenticate(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        // Auth::attempt uses the configured `web` guard -> Operator model, which
        // applies the TenantScope -> only tenant-local rows are matched.
        if (! Auth::guard('web')->attempt(
            ['email' => $this->email, 'password' => $this->password],
            $this->remember,
        )) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var Operator $operator */
        $operator = Auth::guard('web')->user();

        // Activation / suspension / revocation gate. Every non-signable
        // state kicks the operator back out with a message that
        // matches the state, no generic "auth failed" for cases
        // where credentials were actually correct.
        if (! $operator->canSignIn()) {
            Auth::guard('web')->logout();
            RateLimiter::hit($this->throttleKey());

            $message = match (true) {
                $operator->isRevoked() => __('Your access has been revoked. Ask a manager if this is unexpected.'),
                $operator->isSuspended() => __('Your access is temporarily suspended. Ask a manager to restore it.'),
                default => __('Your account isn\'t activated yet. Check your invite email and click the activation link, or ask your manager to resend it.'),
            };

            throw ValidationException::withMessages(['email' => $message]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        $operator->forceFill(['last_login_at' => now()])->save();

        $this->redirectIntended(route('tenant.onboarding'));
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
