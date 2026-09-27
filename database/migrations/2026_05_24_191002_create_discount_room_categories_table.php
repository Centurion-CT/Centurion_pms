<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot: which RoomCategories a discount targets.
 *
 * Targeting precedence in DiscountEngine::whyNotApplicable():
 *   - If the discount has rows in `discount_rooms`     → restrict to those rooms
 *   - If the discount has rows in `discount_room_categories` → restrict to those categories
 *   - If both pivots are empty                          → applies to every room
 *
 * Category targeting is the operator-friendly path: "20% off any
 * Deluxe stay" is one row in this pivot vs N rows (one per Deluxe
 * unit) in `discount_rooms`. Room-level targeting stays available
 * for finer-grained promos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_room_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_category_id')->constrained()->cascadeOnDelete();

            $table->unique(['discount_id', 'room_category_id']);
            $table->index('room_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_room_categories');
    }
};
