<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A verified own domain only takes guests once it answers for the
 * business over HTTPS (docs/MULTITENANCY.md, step 4). The hourly check
 * stamps `custom_domain_live_at`, counts misses in a row, and clears the
 * stamp after a few so guests stay on the platform address until it's
 * fixed.
 *
 * A domain verified before this existed (Centurion's own) is taken as
 * live, so its links don't move to the platform address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('custom_domain_live_at')->nullable()->after('domain_verified_at');
            $table->unsignedSmallInteger('custom_domain_failed_checks')->default(0)->after('custom_domain_live_at');
        });

        DB::table('tenants')
            ->whereNotNull('custom_domain')
            ->whereNotNull('domain_verified_at')
            ->update(['custom_domain_live_at' => DB::raw('domain_verified_at')]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['custom_domain_live_at', 'custom_domain_failed_checks']);
        });
    }
};
