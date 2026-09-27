<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auth;

use App\Models\Admin;
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
 * Admin login Livewire (PRD §18). Distinct from the tenant operator login
 *, uses the `admin` guard, lives only on admin.keverna.com, throttles
 * separately ("admin-" prefix on the throttle key).
 *
 * Deactivated admins (is_active=false) are rejected with an explicit
 * message even when credentials are correct, no point letting them in
 * to be bounced by EnsureActiveAdmin on the next click.
 */
#[Layout('admin.layout')]
class Login extends Component
{
    #[Validate('required|email|max:180')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate(): void
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('admin')->attempt(
            ['email' => $this->email, 'password' => $this->password],
            $this->remember,
        )) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        if (! $admin->is_active) {
            Auth::guard('admin')->logout();
            throw ValidationException::withMessages([
                'email' => __('This admin account is deactivated.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();
        $admin->forceFill(['last_login_at' => now()])->save();

        // If this admin has 2FA enrolled, gate everything behind the
        // TOTP challenge screen. The `admin.2fa.passed` session flag
        // is what the EnsureAdmin2fa middleware checks on every
        // subsequent request; without it we bounce back to
        // /admin/two-factor/challenge until a code is verified.
        if ($admin->hasTwoFactor()) {
            session()->put('admin.2fa.pending', true);
            $this->redirect(route('admin.two-factor.challenge'));
            return;
        }

        // Guard against SESSION_DOMAIN=.keverna.com session-sharing:
        // an `url.intended` written by an auth-guarded request on the
        // central marketing domain or a tenant subdomain would
        // otherwise redirect this admin login to a keverna.com or
        // <slug>.keverna.com URL. Only honour intended URLs that
        // resolve to the admin host; anything else drops back to
        // /dashboard on admin.keverna.com.
        $intended = session()->pull('url.intended');
        $adminHost = (string) config('keverna.admin_domain');
        if (! is_string($intended) || parse_url($intended, PHP_URL_HOST) !== $adminHost) {
            $intended = route('admin.dashboard');
        }
        $this->redirect($intended);
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
        return 'admin-'.Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.admin.auth.login');
    }
}
