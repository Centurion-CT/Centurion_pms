<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sum of paid packages selected during the initial booking. Folded into
 * `total_amount` so Paystack collects it in the same checkout. Packages
 * added after booking ride on `booking_charges` instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('packages_total')->default(0)->after('room_total');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('packages_total');
        });
    }
};
