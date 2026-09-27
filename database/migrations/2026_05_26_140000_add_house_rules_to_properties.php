<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property-level house rules — check-in / check-out windows + free-text
 * policies (cancellation, pets, smoking, children, etc.) shown on the
 * public landing page AND each category-detail page so guests don't
 * book on the wrong assumption.
 *
 * Single JSON column so we can iterate on the shape without a string of
 * follow-up migrations; the Property model exposes a typed accessor
 * that fills defaults for missing keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->json('house_rules')->nullable()->after('nearby_places');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('house_rules');
        });
    }
};
