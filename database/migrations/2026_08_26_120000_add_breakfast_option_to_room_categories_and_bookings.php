<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional second rate tier per category: "with complimentary
 * breakfast". Null on the category = tier disabled (no option shown
 * to the guest / operator). When set, both the guest funnel and every
 * manual reservation surface offer a radio pair — pick either the
 * base rate or the breakfast rate. The booking records which tier
 * was picked so folio + reports can render it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_categories', function (Blueprint $table): void {
            // Nullable = tier not offered for this category. When set,
            // this is a whole-NGN alternative to base_rate (not an
            // add-on delta — a full nightly rate that includes breakfast).
            $table->unsignedBigInteger('breakfast_included_rate')
                ->nullable()
                ->after('base_rate');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->boolean('breakfast_included')
                ->default(false)
                ->after('room_total');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('breakfast_included');
        });
        Schema::table('room_categories', function (Blueprint $table): void {
            $table->dropColumn('breakfast_included_rate');
        });
    }
};
