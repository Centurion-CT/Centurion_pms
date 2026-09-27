<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency stamp for the day-before extend-prompt cron. Nullable
 * timestamp - SendExtendPrompts picks bookings where this is NULL +
 * checkout_date is tomorrow, and stamps `now()` after sending. Prevents
 * duplicate WhatsApps if the cron runs twice, and lets a re-run pick
 * up stragglers if the first attempt failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('extend_prompt_sent_at')->nullable()->after('checkin_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('extend_prompt_sent_at');
        });
    }
};
