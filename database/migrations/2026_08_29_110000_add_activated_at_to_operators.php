<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activation gate for operator accounts.
 *
 *   activated_at NULL     — invitee hasn't set a password yet;
 *                            tenant Login + central PostLoginRouter
 *                            refuse the sign-in with a friendly
 *                            "not activated" message.
 *   activated_at not NULL — regular operator, login flows normally.
 *
 * Backfill: every existing operator predates the invite flow and
 * has been signing in on the platform for weeks — activate them all
 * so the new gate doesn't lock the whole fleet out on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->timestamp('activated_at')->nullable()->after('force_password_change');
        });

        DB::table('operators')->update([
            'activated_at' => DB::raw('COALESCE(last_login_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('activated_at');
        });
    }
};
