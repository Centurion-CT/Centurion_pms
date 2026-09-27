<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Operator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the operator dashboard behind the 3-step onboarding wizard (PRD §6.1.1).
 * Redirects to the next incomplete step until all three are done; lets the
 * request through once Property exists + email_verified_at + the phone step
 * is resolved (either verified OR explicitly skipped — see Operator::hasResolvedPhoneStep).
 *
 * Trusts auth:web to have run first — apply AFTER auth in the middleware stack.
 */
class RequireCompleteOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Operator|null $operator */
        $operator = $request->user('web');

        if ($operator === null) {
            return $next($request);
        }

        $tenant = $operator->tenant;

        if (! $tenant->property()->exists()) {
            return redirect()->route('tenant.onboarding.property');
        }

        if ($operator->email_verified_at === null) {
            return redirect()->route('tenant.onboarding.email');
        }

        if (! $operator->hasResolvedPhoneStep()) {
            return redirect()->route('tenant.onboarding.phone');
        }

        return $next($request);
    }
}
