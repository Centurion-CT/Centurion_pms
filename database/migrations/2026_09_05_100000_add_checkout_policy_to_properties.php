<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property-level checkout policy — hourly late-fee + a threshold in
 * hours past checkout that trips a full-day charge. Sits next to
 * house_rules because it's operational (settled money at checkout),
 * not display copy: the times themselves (check_out_from/to) still
 * live in house_rules and drive both what the guest sees and what
 * this policy is measured against.
 *
 * Shape (see Property::checkoutPolicy() for the canonical accessor):
 *   {
 *     "late_checkout_enabled": bool,
 *     "late_checkout_grace_minutes": int,          // e.g. 30
 *     "late_checkout_hourly_fee": int,             // naira, per started hour
 *     "late_checkout_full_day_after_hours": int    // 0 = never trip full-day
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->json('checkout_policy')->nullable()->after('house_rules');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('checkout_policy');
        });
    }
};
