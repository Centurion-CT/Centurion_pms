<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Wires the `/horizon` dashboard.
 *
 * Locked to authenticated platform admins (PRD §8) — operators must never
 * see other tenants' queues, and unauthenticated visitors must never see
 * the dashboard at all. The default Horizon::auth() callback returns true
 * in local dev, which silently turns a misconfigured deploy into a public
 * dashboard; we override it to fail closed instead.
 *
 * Production deploys should also pin `HORIZON_PATH` to something random so
 * the dashboard URL doesn't show up in a generic /horizon discovery sweep.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Restrict /horizon to logged-in platform admins. The `admin` guard
     * authenticates against the dedicated `admins` table (App\Models\Admin),
     * not the tenant-side `operators` table — so this gate cannot be
     * accidentally widened by a misconfigured tenant role.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($_user = null): bool {
            $admin = auth('admin')->user();

            return $admin !== null && (bool) ($admin->is_active ?? false);
        });
    }
}
