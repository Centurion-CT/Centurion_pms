<?php

declare(strict_types=1);

namespace App\Livewire\Central;

use App\Models\IdentityAccount;
use App\Services\Identity\PostLoginRouter;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Central identity login (docs/unified-login.md). Lives on the marketing
 * domain at keverna.com/login and authenticates against the `identity`
 * guard, then routes per PostLoginRouter to handoff / reservations /
 * chooser.
 *
 * Same generic-error + rate-limit patterns as the tenant-side Login,
 * to avoid making the form an identity-enumeration oracle.
 */
#[Layout('layouts.auth')]
class Login extends Component
{
    #[Validate('required|email|max:180')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Belt-and-braces on top of the framework's redirectUsersTo
     * callback. If the identity guard's cookie somehow slipped past
     * the `guest:identity` middleware (edge case: session was
     * refreshed mid-request), bounce them out of the login form
     * rather than let them stare at a form that no-ops on submit.
     */
    public function mount(): void
    {
        if (\Illuminate\Support\Facades\Auth::guard('identity')->check()) {
            $this->redirectRoute('central.choose', navigate: false);
        }
    }

    public function authenticate(PostLoginRouter $router): mixed
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        $email = IdentityAccount::normaliseEmail($this->email);

        $identity = IdentityAccount::query()
            ->where('email', $email)
            ->whereNotNull('password')
            ->first();

        if ($identity === null || ! Hash::check($this->password, (string) $identity->password)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::guard('identity')->login($identity, $this->remember);
        session()->regenerate();

        $identity->forceFill(['last_login_at' => now()])->save();

        $destination = $router->resolve($identity);

        // Un-activated invite: the identity's only operator links
        // point at accounts that haven't been activated yet. Bounce
        // them out of the session and send them back with a pointer
        // to their invite email, do NOT hand off, or SsoConsume
        // would try to log an inactive operator in.
        if ($destination['kind'] === 'invite_pending') {
            Auth::guard('identity')->logout();
            session()->invalidate();

            throw ValidationException::withMessages([
                'email' => __('Your account isn\'t activated yet. Check your invite email and click the activation link, or ask your manager to resend it.'),
            ]);
        }

        return match ($destination['kind']) {
            'handoff' => $this->redirectRoute(
                'central.handoff',
                ['link' => $destination['link_id']],
                navigate: false,
            ),
            'reservations' => $this->redirectRoute('central.reservations', navigate: false),
            'choose' => $this->redirectRoute('central.choose', navigate: false),
        };
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
        return view('livewire.central.login');
    }
}
