<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OperatorRole;
use App\Models\Operator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff app endpoints (/api/mobile/v1/staff/*) are for housekeepers and
 * porters only. Everyone else uses the web dashboard.
 */
class EnsureStaffAppRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->user();
        if (! $operator instanceof Operator
            || ! in_array($operator->role, [OperatorRole::HouseCleaner, OperatorRole::Porter], true)) {
            return response()->json([
                'error' => 'staff_only',
                'detail' => 'This app is for housekeepers and porters. Use the dashboard on the web instead.',
            ], 403);
        }
        if ($operator->suspended_at !== null || $operator->revoked_at !== null) {
            return response()->json(['error' => 'suspended', 'detail' => 'Your account is paused. Ask a manager.'], 401);
        }

        return $next($request);
    }
}
