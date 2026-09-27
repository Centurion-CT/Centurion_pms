<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent + irreversible tenant deletion. Wipes every row keyed to
 * the tenant across every table that carries a `tenant_id`, plus the
 * tenant row itself + its identity-account links, subscriptions and
 * domains. Runs in a transaction so a mid-cascade failure leaves the
 * DB consistent.
 *
 * Callers are expected to gate this behind:
 *   - super-admin authorisation
 *   - a live 2FA challenge (per-request TOTP, not just session pass)
 *   - a typed tenant-slug confirmation
 *   - a written reason (for the audit log)
 *
 * There is no undo. See the calling Livewire component for the UX.
 */
class DangerousTenantDelete
{
    /**
     * @return int Total rows wiped across all tenant-scoped tables.
     */
    public function handle(Tenant $tenant, Admin $performedBy, string $reason): int
    {
        return DB::transaction(function () use ($tenant, $performedBy, $reason): int {
            $tenantId = (int) $tenant->getKey();
            $total = 0;

            // Every table that carries a tenant_id column gets scanned
            // and wiped. Discovering via information_schema keeps this
            // future-proof: any new tenant-scoped feature slots in
            // without touching this service.
            $tables = $this->tenantScopedTables();

            // Delete children before the tenant row itself so FK
            // constraints (where they exist) don't reject the parent
            // deletion.
            foreach ($tables as $table) {
                $rows = DB::table($table)->where('tenant_id', $tenantId)->delete();
                $total += (int) $rows;
            }

            // Central tables that reference the tenant by FK but aren't
            // themselves tenant-scoped (subscriptions live on the
            // central plane so admins can bill across tenants).
            $centralTables = [
                'subscriptions',
                'identity_operator_links',
                'domains', // if present
            ];
            foreach ($centralTables as $table) {
                if (! Schema::hasTable($table)) continue;
                $col = Schema::hasColumn($table, 'tenant_id') ? 'tenant_id' : null;
                if ($col === null) continue;
                $rows = DB::table($table)->where($col, $tenantId)->delete();
                $total += (int) $rows;
            }

            // Activity log stays as an append-only central record;
            // we don't wipe the audit trail. A final `tenant_deleted`
            // entry closes it off.
            activity('admin')
                ->causedBy($performedBy)
                ->event('tenant_deleted')
                ->withProperties([
                    'tenant_id'   => $tenantId,
                    'tenant_slug' => $tenant->slug,
                    'tenant_name' => $tenant->name,
                    'rows_wiped'  => $total,
                    'reason'      => $reason,
                ])
                ->log(sprintf(
                    'Super-admin %s deleted tenant %s (id %d), wiping %d row%s. Reason: %s',
                    $performedBy->email, $tenant->slug, $tenantId, $total,
                    $total === 1 ? '' : 's', $reason,
                ));

            // Finally the tenant row itself.
            Tenant::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('id', $tenantId)
                ->delete();

            return $total;
        });
    }

    /**
     * Every table that carries a `tenant_id` column. Discovered
     * dynamically via information_schema so a new feature that
     * scopes to tenant just needs the migration - no code touch
     * required here.
     *
     * @return list<string>
     */
    private function tenantScopedTables(): array
    {
        $conn = DB::connection();
        $driver = $conn->getDriverName();
        $db = $conn->getDatabaseName();

        return match ($driver) {
            'mysql' => collect(DB::select(
                "SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'tenant_id'",
                [$db]
            ))->pluck('TABLE_NAME')->all(),

            'pgsql' => collect(DB::select(
                "SELECT DISTINCT table_name FROM information_schema.columns
                 WHERE table_catalog = ? AND column_name = 'tenant_id' AND table_schema = 'public'",
                [$db]
            ))->pluck('table_name')->all(),

            // SQLite path used by feature tests: walk sqlite_master +
            // check each user table for the column.
            'sqlite' => collect(DB::select("SELECT name FROM sqlite_master WHERE type='table'"))
                ->pluck('name')
                ->filter(fn ($t) => is_string($t) && Schema::hasColumn($t, 'tenant_id'))
                ->values()
                ->all(),

            default => [],
        };
    }
}
