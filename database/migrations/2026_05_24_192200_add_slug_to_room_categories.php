<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a URL-friendly slug to RoomCategory for public browse paths:
 *   /categories/{slug}   ← human-readable, SEO-friendlier
 *
 * Slug uniqueness is scoped per (tenant, property) so two tenants
 * can independently use "deluxe-suite". The backfill walks existing
 * rows and stamps a unique slug from each category's `name`, with
 * `-2`, `-3`… suffixes when collisions arise within the same property.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_categories', function (Blueprint $table): void {
            $table->string('slug', 140)->nullable()->after('name');
        });

        // Backfill — slugs need to be unique within a property.
        $rows = DB::table('room_categories')->orderBy('id')->get();
        $used = []; // [propertyId => [slug => true]]

        foreach ($rows as $row) {
            $base = Str::slug((string) ($row->name ?? 'category'));
            if ($base === '') {
                $base = 'category-'.$row->id;
            }
            $pid = (int) $row->property_id;
            $candidate = $base;
            $suffix = 1;
            while (isset($used[$pid][$candidate])) {
                $suffix++;
                $candidate = $base.'-'.$suffix;
            }
            $used[$pid][$candidate] = true;

            DB::table('room_categories')->where('id', $row->id)->update(['slug' => $candidate]);
        }

        Schema::table('room_categories', function (Blueprint $table): void {
            $table->unique(['property_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('room_categories', function (Blueprint $table): void {
            $table->dropUnique(['property_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
