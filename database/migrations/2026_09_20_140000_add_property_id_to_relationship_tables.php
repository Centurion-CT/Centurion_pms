<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: schema-scoping the tenant-wide "relationships" tools per
 * Property so a mixed portfolio (single_unit apartments + hotels
 * under one tenant) doesn't mash all customer / discount / corporate
 * / wallet records into a single list.
 *
 * Before: rows on customers, customer_wallets, discounts, corporates
 * carried tenant_id only. Every Property in the tenant saw the same
 * list.
 *
 * After: each row also carries property_id. Nullable + backfilled to
 * the tenant's oldest property so pre-Phase-4 data still surfaces
 * under the operator's "main" branch. New rows are stamped with the
 * current PropertyContext branch by an observer.
 *
 * Kept nullable (not yet enforced NOT NULL) — a follow-up migration
 * can tighten once the app has been running with stamping in place
 * for a while and we've confirmed no legacy inserters slipped by.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only tables that have a tenant_id column can be safely
        // backfilled against `properties.tenant_id`. `corporates`
        // is intentionally cross-tenant (one corporate can transact
        // with multiple hospitality orgs on the platform) so it's
        // NOT included in this phase — property scoping doesn't map
        // to its semantics cleanly.
        $tables = [
            'customers',
            'customer_wallets',
            'discounts',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) continue;
            if (Schema::hasColumn($table, 'property_id')) continue;

            Schema::table($table, function (Blueprint $t) use ($table): void {
                // FK constraint intentionally omitted so an accidental
                // property deletion doesn't cascade into thousands of
                // wallet / discount / customer rows silently.
                $t->foreignId('property_id')->nullable()->after('tenant_id')->index();
            });

            // Backfill: tenant's oldest property (matches the pre-multi
            // "main" branch) so existing rows still resolve under the
            // operator's default context.
            DB::statement(
                'UPDATE '.$table.' SET property_id = ('
                .' SELECT id FROM properties WHERE properties.tenant_id = '.$table.'.tenant_id'
                .' ORDER BY properties.id ASC LIMIT 1'
                .') WHERE property_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        $tables = ['customers', 'customer_wallets', 'discounts'];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) continue;
            if (! Schema::hasColumn($table, 'property_id')) continue;

            Schema::table($table, function (Blueprint $t) use ($table): void {
                // Drop the auto-generated index name Laravel creates
                // for foreignId(...)->index().
                try { $t->dropIndex($table.'_property_id_index'); } catch (\Throwable) {}
                $t->dropColumn('property_id');
            });
        }
    }
};
