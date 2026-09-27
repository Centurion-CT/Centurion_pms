<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Category-level reservations - a booking can hold a slot against
 * a room category without pinning a specific room until check-in.
 *
 *   bookings.room_id           becomes nullable (was NOT NULL)
 *   bookings.room_category_id  added (nullable FK, backfilled from
 *                              the current room's category so the
 *                              column carries meaningful data for
 *                              every existing booking too)
 *
 *   availability_blocks.room_id           becomes nullable
 *   availability_blocks.room_category_id  added (nullable FK)
 *
 * Existing unique(tenant_id, room_id, date) index STAYS in place -
 * SQL treats NULL room_id rows as distinct so a category-level
 * block (room_id NULL) never collides with a specific-room block
 * on the uniqueness constraint. The category-level availability
 * count is computed at query time (see AvailabilityService's new
 * categoryHasAvailability method).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('room_category_id')
                ->nullable()
                ->after('room_id')
                ->constrained('room_categories')
                ->nullOnDelete();
            $table->index('room_category_id');
        });

        // Backfill: stamp room_category_id on every existing booking
        // from the room it points at, so downstream queries can key
        // off room_category_id uniformly regardless of whether the
        // booking is category-level (room_id NULL) or specific-room.
        DB::statement("
            UPDATE bookings
               SET room_category_id = (
                   SELECT room_category_id
                     FROM rooms
                    WHERE rooms.id = bookings.room_id
               )
             WHERE room_category_id IS NULL
               AND room_id IS NOT NULL
        ");

        // Now make room_id nullable. Doctrine DBAL is needed for
        // ->nullable()->change() on SQLite pre-Laravel 12; the DB
        // driver mismatch shouldn't matter here because
        // change_columns runs via a raw ALTER in the DBAL path.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('room_id')->nullable()->change();
        });

        Schema::table('availability_blocks', function (Blueprint $table): void {
            $table->foreignId('room_category_id')
                ->nullable()
                ->after('room_id')
                ->constrained('room_categories')
                ->nullOnDelete();
            $table->index(['room_category_id', 'date']);
        });

        // Backfill room_category_id on existing availability blocks
        // so category-level queries can pivot cleanly whether the
        // block was created before or after this migration.
        DB::statement("
            UPDATE availability_blocks
               SET room_category_id = (
                   SELECT room_category_id
                     FROM rooms
                    WHERE rooms.id = availability_blocks.room_id
               )
             WHERE room_category_id IS NULL
               AND room_id IS NOT NULL
        ");

        Schema::table('availability_blocks', function (Blueprint $table): void {
            $table->foreignId('room_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['room_category_id']);
            $table->dropIndex(['room_category_id']);
            $table->dropColumn('room_category_id');
        });

        Schema::table('availability_blocks', function (Blueprint $table): void {
            $table->dropForeign(['room_category_id']);
            $table->dropIndex(['room_category_id', 'date']);
            $table->dropColumn('room_category_id');
        });

        // Leaving room_id nullable is safer than trying to reinstate
        // NOT NULL when we'd need to backfill any category-level
        // bookings created between deploy + rollback with real
        // rooms first.
    }
};
