<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency marker for the 24h-before-checkin reminder (PRD §17).
 * One-shot send per booking: set the column the first time the daily cron
 * picks the row up so a re-run on the same day doesn't double-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('checkin_reminder_sent_at')->nullable()->after('balance_reminders_sent');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('checkin_reminder_sent_at');
        });
    }
};
