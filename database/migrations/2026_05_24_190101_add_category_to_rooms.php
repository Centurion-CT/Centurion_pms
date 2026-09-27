<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-unit fields to Room. The shared fields (capacity,
 * pricing, deposit, etc.) move to RoomCategory.
 *
 *   - room_category_id  — FK to room_categories (nullable until
 *                         backfill links every row, then we tighten)
 *   - room_number       — operator-facing unit identifier ("101",
 *                         "A-12", "Garden Cottage"). Unique within a
 *                         property so the front desk never has two
 *                         rooms with the same key.
 *   - internal_notes    — staff-only freeform notes ("door sticks",
 *                         "TV remote in drawer 2", etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->foreignId('room_category_id')->nullable()->after('property_id')
                ->constrained('room_categories')->nullOnDelete();
            $table->string('room_number', 40)->nullable()->after('room_category_id');
            $table->text('internal_notes')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('room_category_id');
            $table->dropColumn(['room_number', 'internal_notes']);
        });
    }
};
