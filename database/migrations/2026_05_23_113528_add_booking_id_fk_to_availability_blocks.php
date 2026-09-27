<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §9 deferred this — bookings table didn't exist when availability_blocks
 * was created. With nullOnDelete: cancelling a booking releases its nights
 * (via the AvailabilityService), but stray rows whose booking was hard-
 * deleted simply lose the FK rather than disappearing — preserves audit
 * trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_blocks', function (Blueprint $table): void {
            $table->foreign('booking_id')
                ->references('id')->on('bookings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('availability_blocks', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
        });
    }
};
