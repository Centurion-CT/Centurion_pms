<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CorporateMemberStatus;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounces corporate-portal sessions whose underlying member has been
 * suspended/offboarded or whose corporate is no longer Active.
 *
 * Layered on top of `auth:corporate` — by the time this runs we
 * already have an authenticated member; we just refuse to honour the
 * session if their status has changed since login (admin suspended
 * them, contract ended, etc).
 */
class EnsureCorporateActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->user('corporate');

        if ($member === null) {
            return $next($request);
        }

        if ($member->status !== CorporateMemberStatus::Active
            || ! ($member->corporate?->status?->isLive() ?? false)) {
            auth('corporate')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return new RedirectResponse(route('corporate.login'));
        }

        return $next($request);
    }
}
