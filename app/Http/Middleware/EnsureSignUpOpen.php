<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows "sign-up isn't open yet" on /signup while `keverna.signup_open`
 * is off. The sign-up component checks the same setting on every step,
 * so a page loaded before it was turned off can't finish either.
 */
class EnsureSignUpOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('keverna.signup_open')) {
            return $next($request);
        }

        return response()->view('platform.signup-closed', [
            'helpEmail' => 'hello@'.config('keverna.central_domain'),
        ]);
    }
}
