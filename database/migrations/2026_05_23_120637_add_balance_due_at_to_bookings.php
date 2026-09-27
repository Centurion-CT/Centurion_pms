<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hold-flow columns (PRD §6.4.3):
 *
 *  - balance_due_at:  when the unpaid balance for an OnHold booking is due.
 *                     Defaults to the check-in date (PRD literal); can be
 *                     bumped earlier via a Room-level offset down the road.
 *  - balance_reminders_sent:  JSON list of milestone integers (7, 3, 1) that
 *                             have already been mailed. Keeps SendBalanceReminders
 *                             idempotent without a separate join table —
 *                             same pattern as subscriptions.reminders_sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('balance_due_at')->nullable()->after('balance_due');
            $table->json('balance_reminders_sent')->nullable()->after('balance_due_at');
            $table->index('balance_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['balance_due_at']);
            $table->dropColumn(['balance_due_at', 'balance_reminders_sent']);
        });
    }
};
