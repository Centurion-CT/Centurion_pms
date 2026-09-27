<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator preference for how /stays online reservations hold
 * inventory:
 *
 *   'room'     — pre-picks a specific room at Reserve time and blocks
 *                it directly. Existing behaviour, safe default.
 *
 *   'category' — books against the category only (room_id NULL,
 *                room_category_id set). Front desk assigns a real
 *                room at check-in via AssignRoomToBooking. Reduces
 *                the category's free-unit count as bookings come in,
 *                but doesn't lock a specific room until check-in.
 *
 * Front-desk bookings ignore this flag; they always pick a room
 * (the operator is standing in front of a room chart).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('stays_online_hold_mode', 16)->default('room')->after('is_listed_publicly');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('stays_online_hold_mode');
        });
    }
};
