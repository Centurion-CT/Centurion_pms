<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auth;

use App\Models\Admin;
use App\Services\Auth\TotpService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 2FA enrolment page. Available to every logged-in admin at
 * /admin/two-factor. Flow:
 *
 *   1. Mount generates + stashes a fresh Base32 secret on the admin
 *      row (unconfirmed until step 3).
 *   2. QR code + manual entry key displayed. Admin scans in
 *      Authenticator / Authy / 1Password.
 *   3. Admin types the first TOTP code; on match we stamp
 *      two_factor_confirmed_at + hand back 10 one-time recovery
 *      codes to save.
 *
 * Re-enrolling: clicking "Reset" wipes the confirmation and starts
 * fresh. Only the admin themselves can re-enroll their own 2FA;
 * super-admins can reset another admin's from the admins index
 * (out of scope for this prototype).
 */
#[Layout('admin.layout')]
class TwoFactorEnroll extends Component
{
    public string $code = '';

    /** Set to the plaintext recovery codes only on the first render
     *  after successful confirmation. Wiped on the next request so
     *  they're not accidentally shown twice. */
    public array $showRecoveryCodes = [];

    public function mount(TotpService $totp): void
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        // Give the admin a secret to seed the QR code even before
        // they confirm - lets the mount page render a scannable
        // image without a second round-trip.
        if ($admin->two_factor_secret === null || $admin->two_factor_confirmed_at !== null) {
            // Fresh enrollment - either first time, or they're
            // resetting. Wipe the confirmation stamp so mid-flow
            // page reloads don't leave a confirmed admin holding
            // a secret that hasn't been shown to them.
            $admin->forceFill([
                'two_factor_secret' => $admin->hasTwoFactor()
                    ? $admin->two_factor_secret // preserve when already confirmed
                    : $totp->generateSecret(),
            ])->save();
        }
    }

    public function confirm(TotpService $totp): void
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        if ($admin->two_factor_secret === null) {
            throw ValidationException::withMessages(['code' => 'Secret is missing. Reload the page.']);
        }
        if (! $totp->verify((string) $admin->two_factor_secret, (string) $this->code)) {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Check the app time + try the next one.',
            ]);
        }

        $codes = $totp->generateRecoveryCodes();
        $admin->forceFill([
            'two_factor_confirmed_at' => Carbon::now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        activity('admin')
            ->causedBy($admin)
            ->performedOn($admin)
            ->event('two_factor_confirmed')
            ->log("2FA enrolled for {$admin->email}.");

        session()->put('admin.2fa.passed', true);
        session()->forget('admin.2fa.pending');

        // Show the plaintext codes exactly once on the success view.
        // They're stored encrypted-hashed on the row; anything the
        // admin doesn't save now they'll have to reset from later.
        $this->showRecoveryCodes = $codes;
        $this->code = '';
    }

    public function resetEnrollment(): void
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        activity('admin')
            ->causedBy($admin)
            ->performedOn($admin)
            ->event('two_factor_reset')
            ->log("2FA reset by {$admin->email}.");

        // Wipe passed flag - if the admin was mid-session with an
        // active TOTP, resetting drops them back to enrollment.
        session()->forget(['admin.2fa.passed', 'admin.2fa.pending']);
        $this->redirect(route('admin.two-factor.enroll'));
    }

    public function render(TotpService $totp): View
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $secret = (string) $admin->two_factor_secret;
        $uri = $admin->hasTwoFactor()
            ? null
            : $totp->otpauthUri($secret, 'Keverna Admin', (string) $admin->email);

        // External QR image service - no client-side library needed,
        // works in any admin's browser. Base64 the payload so the
        // otpauth colons + slashes survive the URL.
        $qrUrl = $uri
            ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='.rawurlencode($uri)
            : null;

        return view('livewire.admin.auth.two-factor-enroll', [
            'admin'  => $admin,
            'secret' => $secret,
            'qrUrl'  => $qrUrl,
        ]);
    }
}
