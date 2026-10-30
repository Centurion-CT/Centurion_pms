<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Services\PostRegistrationLoginLink;
use Illuminate\Http\RedirectResponse;

/**
 * "Open my dashboard" at the end of sign-up. The new manager is signed in
 * on centurionpms.com, but their desk lives on their own address, where
 * this session doesn't reach. Hand them over with a short-lived one-time
 * login link (PostRegistrationLoginLink), made fresh on each click.
 */
class OpenDashboardController extends Controller
{
    public function __invoke(PostRegistrationLoginLink $links): RedirectResponse
    {
        $operator = Operator::query()->withoutGlobalScopes()->with('tenant')
            ->find(session()->get('signup.operator_id'));

        if ($operator === null || $operator->tenant === null) {
            return redirect()->route('platform.login');
        }

        return redirect()->away($links->generate($operator->tenant, $operator));
    }
}
