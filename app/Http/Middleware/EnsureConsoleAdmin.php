<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\ConsoleAdmins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console pages need a signed-in admin whose address is still in
 * CONSOLE_ADMINS; anyone else goes to the console sign-in.
 */
class EnsureConsoleAdmin
{
    public const SESSION_KEY = 'console.admin';

    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->session()->get(self::SESSION_KEY);

        if (! ConsoleAdmins::allows($email)) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('console.login');
        }

        return $next($request);
    }
}
