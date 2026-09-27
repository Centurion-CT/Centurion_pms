<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which renewal reminders (30/14/7/1 days before expiry, per PRD §4.2)
 * have been sent for each subscription. Storing as JSON keeps the
 * SendRenewalReminders command idempotent without a separate join table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->json('reminders_sent')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('reminders_sent');
        });
    }
};
