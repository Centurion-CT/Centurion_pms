<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proper user management fields on operators:
 *
 *   suspended_at  — soft, reversible pause. Login refuses non-null;
 *                    manager can unsuspend to restore access.
 *   revoked_at    — soft "permanent" removal. Login refuses non-null;
 *                    audit history stays. Reversible only by manager
 *                    rescue path (undo action in the roster).
 *   permissions   — JSON array of granted permission keys on top of
 *                    the operator's role. Additive only — nothing
 *                    here can reduce what the role permits. Enum
 *                    lives at App\Enums\Permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->after('activated_at');
            $table->timestamp('revoked_at')->nullable()->after('suspended_at');
            $table->json('permissions')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn(['suspended_at', 'revoked_at', 'permissions']);
        });
    }
};
