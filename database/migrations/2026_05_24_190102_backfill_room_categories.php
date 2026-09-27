<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: every existing Room gets a RoomCategory created
 * from its current values, and is linked to it via room_category_id.
 *
 * Strategy = 1:1 mapping. Operators can later consolidate multiple
 * single-room categories into shared ones via the dashboard. Doing
 * 1:1 here preserves pricing exactly — no surprise rate changes
 * when this migration runs.
 *
 * `room_number` is filled with a stable sequence per property so the
 * front desk has a unique identifier even for properties that never
 * supplied one. Operators rename freely afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rooms = DB::table('rooms')->orderBy('id')->get();
        if ($rooms->isEmpty()) {
            return;
        }

        $perPropertyCounter = [];
        $now = now();

        foreach ($rooms as $room) {
            // Skip rows that somehow already have a category.
            if (! empty($room->room_category_id)) {
                continue;
            }

            $categoryId = DB::table('room_categories')->insertGetId([
                'tenant_id' => $room->tenant_id,
                'property_id' => $room->property_id,
                'name' => $room->name ?? 'Room',
                'type' => $room->type ?? 'Room',
                'description' => $room->description ?? null,
                'capacity_adults' => $room->capacity_adults ?? 1,
                'capacity_children' => $room->capacity_children ?? 0,
                'base_rate' => $room->base_rate ?? 0,
                'monthly_rate' => $room->monthly_rate ?? null,
                'min_nights' => $room->min_nights ?? 1,
                'weekly_discount_percent' => $room->weekly_discount_percent ?? 0,
                'monthly_discount_percent' => $room->monthly_discount_percent ?? 0,
                'deposit_type' => $room->deposit_type ?? 'none',
                'deposit_value' => $room->deposit_value ?? 0,
                'booking_type' => $room->booking_type ?? 'short_let',
                'status' => $room->status ?? 'active',
                'rules' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Sequence room numbers per property starting at 101 — a
            // sane default that operators can override per-room later.
            $key = (int) $room->property_id;
            $perPropertyCounter[$key] = ($perPropertyCounter[$key] ?? 100) + 1;

            DB::table('rooms')
                ->where('id', $room->id)
                ->update([
                    'room_category_id' => $categoryId,
                    'room_number' => (string) $perPropertyCounter[$key],
                ]);
        }
    }

    public function down(): void
    {
        DB::table('rooms')->update([
            'room_category_id' => null,
            'room_number' => null,
        ]);
        DB::table('room_categories')->delete();
    }
};
