<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invite-by-email support for staff invites.
 *
 *   invite_token_hash       — bcrypt hash of the magic-link nonce
 *                             (stored hashed so a DB leak can't be
 *                             turned into a claimable link)
 *   invite_expires_at       — 24-hour link lifetime; enforced by the
 *                             claim controller
 *   force_password_change   — true from invite time until the operator
 *                             picks a new password; the login flow
 *                             bounces them into /settings/profile
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('invite_token_hash', 128)->nullable()->after('password');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token_hash');
            $table->boolean('force_password_change')->default(false)->after('invite_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn(['invite_token_hash', 'invite_expires_at', 'force_password_change']);
        });
    }
};
