<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auth;

use App\Models\Admin;
use App\Services\Auth\TotpService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Post-login TOTP challenge. Shown to any admin who has 2FA
 * enrolled - passing the challenge flips `admin.2fa.passed` in the
 * session, which the middleware then honours on every subsequent
 * request until the session expires.
 *
 * Recovery codes fall through the same submit box; if the input
 * matches a stored recovery code that code is consumed (removed
 * from the array) and the admin is bounced to /admin/two-factor
 * so they can re-enrol.
 */
#[Layout('admin.layout')]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public function verify(TotpService $totp): void
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $key = 'admin-2fa:'.$admin->getKey().'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages([
                'code' => 'Too many wrong codes. Wait a minute + try again.',
            ]);
        }

        $submitted = trim($this->code);

        // Recovery code path - format XXXX-XXXX. If matched, burn
        // the code and force the admin to re-enrol before they
        // regain full access (their app is presumably lost/broken).
        if (str_contains($submitted, '-')) {
            $codes = $admin->two_factor_recovery_codes ?? [];
            $match = false;
            foreach ($codes as $i => $stored) {
                if (hash_equals(strtoupper($stored), strtoupper($submitted))) {
                    unset($codes[$i]);
                    $match = true;
                    break;
                }
            }
            if ($match) {
                $admin->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                RateLimiter::clear($key);
                session()->put('admin.2fa.passed', true);
                session()->forget('admin.2fa.pending');

                activity('admin')
                    ->causedBy($admin)
                    ->performedOn($admin)
                    ->event('two_factor_recovery_used')
                    ->log("2FA recovery code used by {$admin->email}.");

                // Nudge to re-enrol since they burned a code.
                $this->redirect(route('admin.two-factor.enroll'));
                return;
            }
            // Fell through: not a valid recovery code either.
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['code' => 'Code did not match any active recovery entry.']);
        }

        // TOTP path.
        if (! $totp->verify((string) $admin->two_factor_secret, $submitted)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'code' => 'Wrong code. Check your authenticator app and try again.',
            ]);
        }

        RateLimiter::clear($key);
        session()->put('admin.2fa.passed', true);
        session()->forget('admin.2fa.pending');

        $this->redirect(route('admin.dashboard'));
    }

    public function render(): View
    {
        return view('livewire.admin.auth.two-factor-challenge');
    }
}
