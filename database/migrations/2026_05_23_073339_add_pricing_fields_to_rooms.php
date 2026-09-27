<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing knobs the operator tweaks per room (PRD §6.3.2 / §6.4.3):
 *  - weekly/monthly discount % auto-applied at booking time for 7+ / 28+ nights
 *  - deposit_type + deposit_value drive the Place-on-Hold UX (fixed NGN
 *    amount, % of subtotal, or no deposit at all)
 *
 * Defaults match the PRD §6.4.3 example: 30% deposit, no discounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->unsignedTinyInteger('weekly_discount_percent')->default(0)->after('min_nights');
            $table->unsignedTinyInteger('monthly_discount_percent')->default(0)->after('weekly_discount_percent');
            // 'none' | 'fixed' | 'percent'
            $table->string('deposit_type', 16)->default('percent')->after('monthly_discount_percent');
            // NGN amount when deposit_type=fixed; 0–100 when deposit_type=percent
            $table->unsignedInteger('deposit_value')->default(30)->after('deposit_type');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn([
                'weekly_discount_percent',
                'monthly_discount_percent',
                'deposit_type',
                'deposit_value',
            ]);
        });
    }
};
