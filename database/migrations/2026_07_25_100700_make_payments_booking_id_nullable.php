<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Payments were originally always booking-scoped (Paystack booking
 * flow). The POS module introduces walk-in tenders — someone buying
 * a bottle at the bar without a room — where booking_id has to be
 * null. Relax the NOT NULL so those inserts don't crash.
 *
 * Existing rows are unaffected. The foreign-key constraint stays
 * — nullOnDelete still cleans up if a booking is hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // We can't safely re-add NOT NULL — walk-in POS rows may exist.
        // Down is a no-op; forward-only.
    }
};
