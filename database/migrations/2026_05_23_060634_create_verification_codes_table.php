<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time-pass codes for email + phone verification during onboarding (PRD §6.1.1).
 * Codes are stored hashed (never in plaintext) so a DB leak cannot be replayed.
 *
 * Lifecycle per OTP per operator+channel:
 *   - created with code_hash, expires_at = +10 min, attempts = 0, last_sent_at = now
 *   - consumed_at set on first correct entry
 *   - locked_until set when attempts hits 5; further attempts rejected until past lock
 *   - resends issued through the same row by rotating code_hash + expires_at + last_sent_at,
 *     gated by the 60s cooldown vs last_sent_at
 *
 * Carrying state on one row (vs creating one per resend) keeps the attempts +
 * lockout counter authoritative and easy to reason about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16); // 'email' | 'phone'
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
