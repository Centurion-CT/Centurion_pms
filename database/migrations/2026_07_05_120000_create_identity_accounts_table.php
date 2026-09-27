<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central identity table introduced by the unified-login spec
 * (docs/unified-login.md). Lives on the central domain, outside the
 * BelongsToTenant scope — an identity can link to multiple operator
 * rows across tenants, and later to guest customer rows.
 *
 * Phase 1: only operators get linked here (via identity_operator_links).
 * Guests come in Phase 2 with identity_guest_links.
 *
 * `password` is nullable because Phase 2 will introduce magic-link-only
 * guest identities that never set a password. Phase 1 backfill copies
 * the operator's existing bcrypt hash verbatim, so no double-hashing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->timestamp('last_login_at')->nullable();
            // The link the identity chose "take me straight here next time" on.
            // Nullable FK — we defer the actual `foreign` here because
            // identity_operator_links doesn't exist yet in this migration.
            // The FK gets added in the next migration.
            $table->unsignedBigInteger('preferred_link_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_accounts');
    }
};
