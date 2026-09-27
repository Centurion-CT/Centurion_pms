<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bookings.review_invite_sent_at` - stamped the first time
 * MarkCheckedOut queues a review invite for a Keverna Stays
 * marketplace booking. Prevents the invite being re-sent if a
 * check-out is undone + redone or the action gets re-run for some
 * other reason.
 *
 * Nullable + no backfill: pre-existing checked-out bookings never
 * get an invite retroactively. Only forward-going marketplace
 * checkouts trigger the mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('review_invite_sent_at')->nullable()->after('checkout_date');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('review_invite_sent_at');
        });
    }
};
