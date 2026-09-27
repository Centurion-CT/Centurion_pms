<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the dokwe-side domain row id keverna received when it attached
 * `{slug}.keverna.com` (subdomain) or the operator's custom domain. Two
 * columns instead of one because:
 *
 *   - Subdomain and custom domain are independent — a tenant might have
 *     either, both, or (during onboarding) neither.
 *   - On tenant offboarding we want to issue exactly one DELETE per row
 *     to dokwe's /domains endpoint without having to remember which kind
 *     was the most recent.
 *
 * Both are UUID strings — dokwe's API returns id as a uuid. Stored as
 * `char(36)` for portability (we don't bind to MySQL's UUID type so
 * factory + dev seeders stay simple).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->char('subdomain_dokwe_id', 36)->nullable()->after('subdomain_provisioned_at');
            $table->char('custom_domain_dokwe_id', 36)->nullable()->after('custom_domain_status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['subdomain_dokwe_id', 'custom_domain_dokwe_id']);
        });
    }
};
