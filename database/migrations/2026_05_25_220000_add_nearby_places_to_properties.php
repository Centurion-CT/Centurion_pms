<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-editable "places nearby" — replaces the map iframe on the
 * landing with a useful list of landmarks/transit options. Each entry
 * is a small array { label, distance, mode } stored as JSON; rendered
 * as a card on the public landing's About section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $t): void {
            $t->json('nearby_places')->nullable()->after('amenities');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $t): void {
            $t->dropColumn('nearby_places');
        });
    }
};
