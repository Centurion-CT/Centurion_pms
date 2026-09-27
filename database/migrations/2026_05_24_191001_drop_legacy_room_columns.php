<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 left the legacy shared-fields columns on `rooms` for the
 * dual-source transition period. Phase 2 drops them — RoomCategory
 * is now the single source of truth for these fields.
 *
 * The accessors on Room continue to expose `$room->base_rate` etc.
 * but now delegate to the linked category. Tight constraint on
 * `room_category_id` (NOT NULL) is added here too so every Room
 * always has somewhere to look up its rates / capacity.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tighten the FK first — every row got linked in the Phase 1
        // backfill, so this is just a constraint addition.
        // SQLite can't ALTER a column to NOT NULL without copying the
        // whole table; we skip the NOT NULL there and rely on the
        // application-layer guarantee (Room::save() always sets it).
        //
        // The FK was originally created ON DELETE SET NULL, which MySQL
        // refuses to combine with a NOT NULL column. Drop the FK, tighten
        // the column, then re-add it as RESTRICT — a room without a
        // category is meaningless, so blocking the delete is correct.
        if (\DB::getDriverName() !== 'sqlite') {
            Schema::table('rooms', function (Blueprint $table): void {
                $table->dropForeign(['room_category_id']);
            });

            Schema::table('rooms', function (Blueprint $table): void {
                $table->foreignId('room_category_id')->nullable(false)->change();
            });

            Schema::table('rooms', function (Blueprint $table): void {
                $table->foreign('room_category_id')
                    ->references('id')->on('room_categories')
                    ->restrictOnDelete();
            });
        }

        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn([
                'name',
                'type',
                'description',
                'capacity_adults',
                'capacity_children',
                'base_rate',
                'monthly_rate',
                'min_nights',
                'weekly_discount_percent',
                'monthly_discount_percent',
                'deposit_type',
                'deposit_value',
                'booking_type',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('name', 120)->nullable();
            $table->string('type', 64)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('capacity_adults')->nullable();
            $table->unsignedSmallInteger('capacity_children')->nullable();
            $table->unsignedBigInteger('base_rate')->nullable();
            $table->unsignedBigInteger('monthly_rate')->nullable();
            $table->unsignedSmallInteger('min_nights')->nullable();
            $table->unsignedSmallInteger('weekly_discount_percent')->nullable();
            $table->unsignedSmallInteger('monthly_discount_percent')->nullable();
            $table->string('deposit_type', 16)->nullable();
            $table->unsignedBigInteger('deposit_value')->nullable();
            $table->string('booking_type', 16)->nullable();
        });

        if (\DB::getDriverName() !== 'sqlite') {
            Schema::table('rooms', function (Blueprint $table): void {
                $table->dropForeign(['room_category_id']);
            });

            Schema::table('rooms', function (Blueprint $table): void {
                $table->foreignId('room_category_id')->nullable()->change();
            });

            Schema::table('rooms', function (Blueprint $table): void {
                $table->foreign('room_category_id')
                    ->references('id')->on('room_categories')
                    ->nullOnDelete();
            });
        }
    }
};
