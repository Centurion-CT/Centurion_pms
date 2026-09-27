<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Magic-link claim ledger for the Phase 2 guest onboarding flow
 * (docs/unified-login.md §3 "Backfill / Guests, lazy Phase 2").
 *
 * One row per invite. Written by SendGuestClaimInvite when a booking
 * confirmation fires and the guest hasn't already been claimed. The
 * claim controller (central /claim) verifies the nonce, marks it
 * consumed, and runs the cross-tenant Customer merge.
 *
 * `booking_id` is stored as a nullable soft reference (no FK) — a
 * booking could be soft-deleted or archived before the guest clicks,
 * and we don't want cascade deletes to wipe the claim history.
 * `origin_tenant_id` is the tenant that triggered the invite; used
 * only for the "You just booked at X" pitch in the email body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_claim_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('nonce')->unique();
            // Email is captured (not FK'd) because at issuance time the
            // matching identity_account may not yet exist.
            $table->string('email');
            $table->unsignedBigInteger('origin_tenant_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_ip', 45)->nullable();
            // Populated at consume time so we know which identity ended
            // up owning this claim. Denormalised — the audit query
            // "who's identity_account did this token materialize into"
            // is a common one and joining through email is fuzzier.
            $table->unsignedBigInteger('resolved_identity_account_id')->nullable();
            $table->timestamps();

            $table->index(['email', 'consumed_at']);
            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_claim_tokens');
    }
};
