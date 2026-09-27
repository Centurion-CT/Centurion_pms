<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\PostRegistrationLoginLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Consumes the encrypted post-registration token from PostRegistrationLoginLink
 * and signs the operator in to their tenant subdomain (PRD §6.1.1 — operator
 * "redirected to dashboard" after onboarding).
 *
 * Token contents are verified server-side; expired, tampered, or wrong-tenant
 * tokens 403.
 */
class PostRegistrationLoginController extends Controller
{
    public function __invoke(Request $request, PostRegistrationLoginLink $linkBuilder): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $tenant = Tenant::current();

        if ($tenant === null || $token === '') {
            throw new HttpException(403, 'Invalid post-registration token.');
        }

        $payload = $linkBuilder->verify($token, $tenant);

        if ($payload === null) {
            throw new HttpException(403, 'Invalid post-registration token.');
        }

        $operator = Operator::query()->find($payload['operator_id']);

        if ($operator === null || $operator->tenant_id !== $tenant->id) {
            throw new HttpException(403, 'Invalid post-registration token.');
        }

        // PRD §10: session fixation prevention — re-issue the session id on login.
        $request->session()->regenerate();
        auth()->guard('web')->login($operator);
        $operator->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('tenant.onboarding');
    }
}
