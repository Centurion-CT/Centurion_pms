<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 follow-up: relax the tenant-wide email uniqueness on
 * customers to a per-property one. Aligns with the "CRM is per
 * property" model already applied on the Index / Show / Wallet
 * paths — a guest visiting Property A and Property B under the
 * same tenant gets one Customer row per branch, not a single
 * shared row that pulls history from both.
 *
 * Backfill already stamped every existing customers row with the
 * tenant's oldest property (2026_09_20_140000), so the new unique
 * key can't collide with existing data — the pre-migration
 * (tenant, email) constraint guaranteed one row per (tenant,
 * email), which trivially satisfies (tenant, ANY property, email).
 *
 * Marketplace checkout guest-match by phone is intentionally
 * cross-tenant so a returning guest at any Keverna Stays property
 * still gets auto-filled; only the per-tenant CRM writes are
 * scoped by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) return;

        Schema::table('customers', function (Blueprint $t): void {
            try { $t->dropUnique('customers_tenant_id_email_unique'); } catch (\Throwable) {}
        });

        Schema::table('customers', function (Blueprint $t): void {
            $t->unique(['tenant_id', 'property_id', 'email']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) return;

        Schema::table('customers', function (Blueprint $t): void {
            try { $t->dropUnique(['tenant_id', 'property_id', 'email']); } catch (\Throwable) {}
        });

        Schema::table('customers', function (Blueprint $t): void {
            $t->unique(['tenant_id', 'email']);
        });
    }
};
