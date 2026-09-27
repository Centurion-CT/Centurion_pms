<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo-fence + trust-score threshold for the guest self-service
 * portal. When set, portal actions from guests whose captured
 * device coords score below the threshold get queued for front-
 * desk approval instead of executing straight through.
 *
 * Sensible defaults are "off":
 *   - fence radius = 0  → gate disabled (portal behaves as before)
 *   - min score    = 0  → nothing gets gated even if a fence is set
 *
 * The Property settings screen ships preset picks (Tight ≈ 50m,
 * Standard ≈ 200m, Loose ≈ 1km, Off) so operators don't need to
 * think in metres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->unsignedInteger('geo_fence_radius_meters')->default(0)->after('longitude');
            $table->unsignedTinyInteger('guest_coord_min_score')->default(0)->after('geo_fence_radius_meters');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn(['geo_fence_radius_meters', 'guest_coord_min_score']);
        });
    }
};
