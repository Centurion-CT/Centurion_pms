<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Logs the central identity session out. Mirrors CorporateLogoutController.
 * Tenant-subdomain sessions are separate — they get invalidated on their
 * own subdomain by the tenant logout flow.
 */
class IdentityLogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        auth()->guard('identity')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
