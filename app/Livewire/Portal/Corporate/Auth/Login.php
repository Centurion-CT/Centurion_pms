<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Corporate\Auth;

use App\Enums\CorporateMemberRole;
use App\Enums\CorporateMemberStatus;
use App\Models\CorporateMember;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Corporate-admin sign in.
 *
 * Restricted to role='admin' members, regular members can't sign in
 * to v1. The check is in `authenticate()` so the same form can serve
 * v2's member-portal extension without UI changes.
 *
 * On success we stamp `last_login_at` for the activity feed and bounce
 * to the portal dashboard.
 */
#[Layout('layouts.corporate-portal')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function authenticate(): void
    {
        $data = $this->validate([
            'email'    => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $email = strtolower(trim($data['email']));

        // The same limit as staff sign-in: these admins spend a company
        // wallet, so guessing has to be slow here too.
        $throttleKey = 'corporate-login:'.Str::transliterate($email.'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            event(new Lockout(request()));

            $this->refuse(__('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]));
        }

        /** @var CorporateMember|null $member */
        $member = CorporateMember::query()
            ->where('email', $email)
            ->whereNotNull('password')
            ->first();

        // We intentionally use one error message for "no such user"
        // and "wrong password" so the form doesn't double as a
        // member-enumeration oracle.
        $genericError = "That email and password don't match. Try again.";

        if ($member === null
            || ! Hash::check($data['password'], (string) $member->password)
            || $member->role !== CorporateMemberRole::Admin
            || $member->status !== CorporateMemberStatus::Active
            || ! ($member->corporate?->status?->isLive() ?? false)) {
            RateLimiter::hit($throttleKey);

            $this->refuse($genericError);
        }

        RateLimiter::clear($throttleKey);

        Auth::guard('corporate')->login($member, $this->remember);
        $member->forceFill(['last_login_at' => now()])->save();

        $this->redirectRoute('corporate.dashboard', navigate: true);
    }

    /** The page shows this above the form and puts focus on the password. */
    private function refuse(string $message): never
    {
        $this->dispatch('sign-in-refused');

        throw ValidationException::withMessages(['email' => $message]);
    }

    public function render(): View
    {
        return view('livewire.portal.corporate.auth.login');
    }
}
