<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the no-show timestamp + lengthens booking_status to fit the new
 * `no_show` enum value (longest string growing from 'checked_out' = 11
 * to 'no_show' = 7, so the existing column already fits; this migration
 * keeps the no_show_at column in lockstep with the enum addition).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('no_show_at')->nullable()->after('booking_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('no_show_at');
        });
    }
};
