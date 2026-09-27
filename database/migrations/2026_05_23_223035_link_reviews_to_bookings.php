<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest-submitted reviews link back to the booking they slept on (PRD
 * post-§19 review submission). The unique index enforces one review per
 * booking — guests can't double-post by reloading.
 *
 * `booking_id` is nullable because operator-authored reviews (the existing
 * dashboard create flow) still float free without a booking link.
 *
 * `review_prompt_sent_at` on bookings is the idempotency marker for the
 * daily "would you mind leaving a review?" email — same one-shot pattern
 * as checkin_reminder_sent_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->after('room_id')->constrained()->nullOnDelete();
            $table->unique('booking_id');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('review_prompt_sent_at')->nullable()->after('checkin_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique(['booking_id']);
            $table->dropForeign(['booking_id']);
            $table->dropColumn('booking_id');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('review_prompt_sent_at');
        });
    }
};
