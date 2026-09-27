<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment rows can now belong to a Corporate (via corporate_id) as
 * well as a Booking. Corp AR payments — recorded through the Corporate
 * Ledger — get mirrored here so they land in the tenant-wide Payments
 * page + KPIs. corporate_payment_id points back at the canonical
 * CorporatePayment source-of-truth row for the audit trail.
 *
 * booking_id is already nullable from
 * 2026_07_25_100700_make_payments_booking_id_nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('corporate_id')->nullable()->after('booking_id')
                ->constrained('corporates')->nullOnDelete();
            $table->foreignId('corporate_payment_id')->nullable()->after('corporate_id')
                ->constrained('corporate_payments')->nullOnDelete();
            $table->index(['tenant_id', 'corporate_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'corporate_id']);
            $table->dropForeign(['corporate_id']);
            $table->dropForeign(['corporate_payment_id']);
            $table->dropColumn(['corporate_id', 'corporate_payment_id']);
        });
    }
};
