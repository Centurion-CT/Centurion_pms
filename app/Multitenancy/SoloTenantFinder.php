<?php

declare(strict_types=1);

namespace App\Multitenancy;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

/**
 * Single-install tenant resolver. Returns the sole `tenants` row on
 * every request, regardless of host — this build serves exactly one
 * hotel per deployment so there's no domain sharding to do.
 *
 * Returns null on a fresh install (no Tenant row yet), which raises
 * NoCurrentTenant. The exception handler in bootstrap/app.php catches
 * that and 302s to `/setup` so the operator lands on the install
 * wizard instead of a 500.
 *
 * The result is cached per request (static) because `NeedsTenant`,
 * the mobile API middleware, and Livewire lifecycle all resolve the
 * finder — repeat DB hits would be wasteful.
 */
class SoloTenantFinder extends TenantFinder
{
    private static ?Tenant $cached = null;

    private static bool $lookedUp = false;

    public function findForRequest(Request $request): ?IsTenant
    {
        if (! self::$lookedUp) {
            self::$cached = Tenant::query()->oldest('id')->first();
            self::$lookedUp = true;
        }

        return self::$cached;
    }

    /**
     * Clears the request-scoped cache. Called from the setup wizard
     * right after it creates the singleton so the next request (the
     * post-setup redirect to the dashboard) picks it up.
     */
    public static function forget(): void
    {
        self::$cached = null;
        self::$lookedUp = false;
    }
}
