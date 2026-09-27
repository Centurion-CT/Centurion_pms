<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the reservation window is allocated across room categories.
 * Example: 2 Standard rooms + 1 Suite for the same window. The rate
 * that applies at booking-time is still resolved via CorporateRate
 * (this row is about allocation, not price).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_reservation_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_reservation_id')
                ->constrained('corporate_reservations')->cascadeOnDelete();
            $table->foreignId('room_category_id')
                ->constrained('room_categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('rooms_count')->default(1);
            $table->timestamps();

            $table->unique(['corporate_reservation_id', 'room_category_id'],
                'corp_res_cat_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_reservation_categories');
    }
};
