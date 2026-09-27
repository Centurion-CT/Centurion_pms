<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets corporate-admin members sign into the self-service portal.
 *
 * Regular members (role='member') don't need auth credentials in v1
 * — their identity is verified at booking time by the email they
 * type into the booking form. Only role='admin' members ever set a
 * password, but the column is on the shared table so v2 can give
 * members spend-history access without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporate_members', function (Blueprint $t): void {
            $t->string('password')->nullable()->after('phone');
            $t->rememberToken()->after('password');
            $t->dateTime('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('corporate_members', function (Blueprint $t): void {
            $t->dropColumn(['password', 'remember_token', 'last_login_at']);
        });
    }
};
