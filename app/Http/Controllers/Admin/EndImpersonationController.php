<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Logs the impersonated operator back out and bounces the admin to the
 * tenant detail page (PRD §18). The session flag `impersonator_admin_id`
 * is the only way back into the admin dashboard from a tenant subdomain
 * — we never automatically log the admin back in on admin.keverna.com.
 */
class EndImpersonationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away(
            'https://'.(string) config('keverna.admin_domain').'/tenants/'.$tenant->id,
        );
    }
}
