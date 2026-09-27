<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-generated UUID stamped on reservations that were queued
 * offline. The /offline-sync endpoint short-circuits duplicates
 * against this column so a queued item resent after a network
 * hiccup (or two operators racing on the same queue) never lands
 * as two separate bookings.
 *
 * Unique + nullable — every offline-queued booking gets one, but
 * bookings created through the normal online path leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->uuid('offline_key')->nullable()->unique()->after('booking_ref');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropUnique(['offline_key']);
            $table->dropColumn('offline_key');
        });
    }
};
