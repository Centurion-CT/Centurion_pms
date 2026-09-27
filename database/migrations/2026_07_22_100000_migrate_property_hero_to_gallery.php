<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The tenant-branding UI split "single hero image" and "gallery carousel"
 * into two separate media collections (`hero` and `gallery`) on the
 * `properties` model. Operators found the split confusing — PublicLandingController
 * already just falls back from gallery to hero, so functionally they're
 * one collection with one item marked as the fallback.
 *
 * This migration collapses them: every `hero` media row moves into the
 * `gallery` collection with `custom_properties.is_hero = true`, matching
 * how the per-room MediaManager already models a "hero" tile within its
 * own gallery. After migration, PublicLandingController drops the hero
 * fallback branch and there's a single Blade component (`<x-photo-manager />`)
 * covering every property/room photo surface.
 *
 * Idempotent — properties with no hero image are skipped. Runs on the
 * media table via the spatie/laravel-medialibrary schema, not via
 * Media::query() (Media has a global tenant scope; this migration runs
 * in central context during deploy so we go through the query builder).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('media')
            ->where('collection_name', 'hero')
            ->where('model_type', \App\Models\Property::class)
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $props = json_decode((string) ($row->custom_properties ?? '{}'), true);
                    if (! is_array($props)) {
                        $props = [];
                    }
                    $props['is_hero'] = true;

                    // Prepend into gallery: bump every existing gallery
                    // order_column by 1, then insert this row at order 1.
                    DB::table('media')
                        ->where('model_type', \App\Models\Property::class)
                        ->where('model_id', $row->model_id)
                        ->where('collection_name', 'gallery')
                        ->increment('order_column');

                    DB::table('media')
                        ->where('id', $row->id)
                        ->update([
                            'collection_name'  => 'gallery',
                            'custom_properties' => json_encode($props, JSON_UNESCAPED_SLASHES),
                            'order_column'     => 1,
                            'updated_at'       => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Reverse: move any is_hero=true gallery items back to a hero
        // collection. Filter in PHP rather than SQL so the query stays
        // portable across MySQL (JSON_EXTRACT) and PostgreSQL
        // (::jsonb->>). Best-effort — we can't restore original ordering.
        DB::table('media')
            ->where('collection_name', 'gallery')
            ->where('model_type', \App\Models\Property::class)
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $props = json_decode((string) ($row->custom_properties ?? '{}'), true);
                    if (! is_array($props) || empty($props['is_hero'])) {
                        continue;
                    }
                    unset($props['is_hero']);
                    DB::table('media')
                        ->where('id', $row->id)
                        ->update([
                            'collection_name'   => 'hero',
                            'custom_properties' => json_encode($props ?: [], JSON_UNESCAPED_SLASHES),
                            'updated_at'        => now(),
                        ]);
                }
            });
    }
};
