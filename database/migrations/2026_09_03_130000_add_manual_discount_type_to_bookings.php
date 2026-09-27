<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track HOW the operator entered the ad-hoc discount at booking time
 * — as a percentage (default) or as a flat ₦ amount. Percent is
 * historical default so back-filling with 'percent' matches every
 * booking created before the toggle shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('manual_discount_type', 16)
                ->default('percent')
                ->after('manual_discount_by_operator_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('manual_discount_type');
        });
    }
};
