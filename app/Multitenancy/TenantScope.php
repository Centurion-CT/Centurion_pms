<?php

declare(strict_types=1);

namespace App\Multitenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains all queries on a tenant-owned model to the currently-active tenant.
 *
 * When NO tenant is current (central context: admin panel, queue worker that
 * forgot to switch, artisan command, etc.) the scope refuses to run queries
 * by returning an unsatisfiable predicate. This is the single most important
 * guard against the "Critical" cross-tenant data leak risk in PRD §12.
 *
 * To intentionally query across tenants (e.g. the platform admin panel) opt
 * out per call via `Model::withoutGlobalScope(TenantScope::class)`.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            // No tenant in context => no rows returned. Fail closed.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $tenant->getKey()
        );
    }
}
