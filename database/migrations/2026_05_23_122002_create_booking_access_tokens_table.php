<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token-based guest portal access (PRD §6.4.4 / §13).
 *
 * One row per issued portal link. Stored hashed so a DB leak can't be
 * replayed against the production URL. Tokens are 48 raw chars (256+ bits
 * of entropy); the hash is sha-256 — fast (we verify on every portal hit)
 * and adequate for a one-shot URL token.
 *
 * `last_used_at` is a hygiene marker, not a single-use lock — the guest
 * may click their email link, view, then click again to cancel. PRD §13
 * "single-use for sensitive actions" intent is enforced at the action
 * layer (CancelBooking refuses if booking already Cancelled, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_access_tokens');
    }
};
