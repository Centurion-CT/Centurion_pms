<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ConsoleUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console pages need a signed-in console user who still exists and has a
 * password (docs/MULTITENANCY.md, step 7); `console.admin:owner` also
 * needs the owner role. Anyone else goes to the console sign-in, or the
 * setup page while no console user exists yet.
 */
class EnsureConsoleAdmin
{
    public const SESSION_KEY = 'console.user_id';

    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = self::user();

        if ($user === null) {
            $request->session()->forget(self::SESSION_KEY);

            return ConsoleUser::query()->exists()
                ? redirect()->route('console.login')
                : redirect()->route('console.setup');
        }

        abort_if($role === 'owner' && ! $user->isOwner(), 403);

        return $next($request);
    }

    /** The signed-in console user, or null. */
    public static function user(): ?ConsoleUser
    {
        $id = session(self::SESSION_KEY);
        $user = $id === null ? null : ConsoleUser::query()->find($id);

        return $user !== null && $user->isActive() ? $user : null;
    }
}
