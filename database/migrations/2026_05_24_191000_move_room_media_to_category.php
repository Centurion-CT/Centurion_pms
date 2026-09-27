<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spatie media-library stores media polymorphically — the `media`
 * table carries `model_type` + `model_id` columns. Phase 1 left
 * existing room photos on the Room model; Phase 2 promotes them to
 * their RoomCategory (which is now the canonical owner of "what does
 * a Deluxe / Suite / Studio look like").
 *
 * Because Phase 1's backfill produced one category per room (1:1
 * mapping), this UPDATE is a clean re-pointing — no merging, no
 * deduping, no collection-cap collisions.
 *
 * Operators who later consolidate categories will need to manage
 * photos through the category form — the per-room photo manager
 * disappears in the same Phase 2 deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roomClass = \App\Models\Room::class;
        $categoryClass = \App\Models\RoomCategory::class;

        // Pull every (room_id, room_category_id) we can resolve. The
        // SQL is intentionally vanilla so MySQL + SQLite both run it.
        $links = DB::table('rooms')
            ->whereNotNull('room_category_id')
            ->pluck('room_category_id', 'id');

        foreach ($links as $roomId => $categoryId) {
            DB::table('media')
                ->where('model_type', $roomClass)
                ->where('model_id', $roomId)
                ->update([
                    'model_type' => $categoryClass,
                    'model_id' => $categoryId,
                ]);
        }
    }

    public function down(): void
    {
        // Best-effort reverse: walk every Room and pull back media
        // that lives on its category. If two rooms shared one
        // category (manual consolidation), media will collapse onto
        // the first room.
        $roomClass = \App\Models\Room::class;
        $categoryClass = \App\Models\RoomCategory::class;

        $links = DB::table('rooms')
            ->whereNotNull('room_category_id')
            ->orderBy('id')
            ->pluck('room_category_id', 'id');

        $consumed = [];
        foreach ($links as $roomId => $categoryId) {
            if (isset($consumed[$categoryId])) {
                continue;
            }
            $consumed[$categoryId] = true;
            DB::table('media')
                ->where('model_type', $categoryClass)
                ->where('model_id', $categoryId)
                ->update([
                    'model_type' => $roomClass,
                    'model_id' => $roomId,
                ]);
        }
    }
};
