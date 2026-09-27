<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Tenant-aware Spatie Activity model.
 *
 * Two things on top of the parent class:
 *
 *  - `tenant_id` is stamped automatically on every saved row, taken from the
 *    currently-bound tenant (or null when there is no bound tenant — central
 *    jobs like ProvisionTenantSubdomain still log).
 *
 *  - Reads default to the current tenant via a global scope so the operator
 *    dashboard can't see another tenant's audit rows. Central command-line
 *    workers and the admin panel lift the scope explicitly when they need
 *    cross-tenant visibility.
 *
 * `tenant_id` is left out of the standard {@see BelongsToTenant} trait
 * because that trait throws when no tenant is bound — which would break
 * legitimate central writes.
 */
class Activity extends BaseActivity
{
    public static function boot(): void
    {
        parent::boot();

        self::creating(function (self $activity): void {
            if ($activity->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenant = Tenant::current();
            if ($tenant instanceof Tenant) {
                $activity->setAttribute('tenant_id', $tenant->getKey());
            }
        });

        self::addGlobalScope('tenant', function (Builder $query): void {
            $tenant = Tenant::current();
            if (! $tenant instanceof Tenant) {
                return;
            }
            $query->where('activity_log.tenant_id', $tenant->getKey());
        });
    }
}
