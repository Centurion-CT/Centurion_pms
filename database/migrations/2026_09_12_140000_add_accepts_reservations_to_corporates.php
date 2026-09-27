<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-corp switch: are we open to accepting NEW reservations for this
 * org? A tenant Manager can pause a corp (e.g. finance dispute, expired
 * MOU) without deleting it — existing bookings + rates stay intact but
 * the reservation-creation surfaces refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporates', function (Blueprint $table): void {
            $table->boolean('accepts_reservations')->default(true)
                ->after('kyc_notes');
        });
    }

    public function down(): void
    {
        Schema::table('corporates', function (Blueprint $table): void {
            $table->dropColumn('accepts_reservations');
        });
    }
};
