<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Front-desk ad-hoc discount on a manual reservation. Distinct from
 * the pre-configured Discount / BookingDiscount rules (promo codes,
 * seasonal auto-apply): those model marketing programmes; this one
 * is a per-reservation one-off decision by whoever is at the counter.
 *
 * Three columns:
 *   - manual_discount_percent (0.00 to 100.00)
 *   - manual_discount_amount  (₦ the percent resolved to, denormalised
 *                              so reports can sum without recomputing)
 *   - manual_discount_by_operator_id (audit — which staff issued it)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->decimal('manual_discount_percent', 5, 2)->default(0)->after('total_amount');
            $table->unsignedInteger('manual_discount_amount')->default(0)->after('manual_discount_percent');
            $table->foreignId('manual_discount_by_operator_id')
                ->nullable()
                ->after('manual_discount_amount')
                ->constrained('operators')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manual_discount_by_operator_id');
            $table->dropColumn(['manual_discount_percent', 'manual_discount_amount']);
        });
    }
};
