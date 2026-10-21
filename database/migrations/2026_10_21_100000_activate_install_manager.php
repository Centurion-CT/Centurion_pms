<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for installs made before SeedInstallation stamped
 * activated_at: the setup-wizard Manager was left un-activated and
 * could not sign back in after logging out. That operator is the one
 * whose email matches the tenant's email. Invited staff are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('operators')
            ->whereNull('activated_at')
            ->whereNull('revoked_at')
            ->where('role', 'manager')
            ->whereIn('email', DB::table('tenants')->select('email'))
            ->update(['activated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Data backfill — nothing to reverse.
    }
};
