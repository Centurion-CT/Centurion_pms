<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * If the current admin has 2FA enrolled, block every request until
 * they pass the TOTP challenge for this session. The pass flag is
 * set by TwoFactorChallenge on success and by TwoFactorEnroll after
 * a first-time confirmation (so the admin doesn't get bounced
 * straight to the challenge screen the moment they enrol).
 *
 * Two-factor pages themselves are exempted so admins can enrol +
 * challenge without a redirect loop.
 */
class EnsureAdmin2fa
{
    public function handle(Request $request, Closure $next)
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();
        if ($admin === null) return $next($request);

        $exempt = [
            'admin.two-factor.enroll',
            'admin.two-factor.challenge',
            'admin.logout',
        ];
        if (in_array($request->route()?->getName(), $exempt, true)) {
            return $next($request);
        }

        if ($admin->hasTwoFactor() && ! session()->get('admin.2fa.passed')) {
            return redirect()->route('admin.two-factor.challenge');
        }

        return $next($request);
    }
}
