<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two shapes of property — the operator picks at create time and
 * never changes:
 *
 *   'multi_room'   — hotels, apartment complexes, guest houses; the
 *                    property has RoomCategories with N rooms each,
 *                    operator manages inventory + housekeeping per
 *                    room. Default.
 *
 *   'single_unit'  — a standalone apartment / condo / bungalow that
 *                    IS the listing. Backing model still holds one
 *                    (hidden) category + one (hidden) room so the
 *                    availability / pricing / rate-rule engine keeps
 *                    working with zero fork; but the sidebar hides
 *                    Categories / Rooms / Housekeeping, and the
 *                    "Add another apartment" flow spins up a new
 *                    Property row instead of a new Room.
 *
 * This unlocks "one owner, N standalone apartments in different
 * neighbourhoods, one login" without duplicating the whole pricing
 * + booking surface for a parallel model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('unit_mode', 16)->default('multi_room')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('unit_mode');
        });
    }
};
