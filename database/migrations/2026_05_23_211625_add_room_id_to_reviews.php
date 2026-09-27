<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-room reviews. A review can either target a specific room
 * (room_id set) or be property-wide (room_id null). The public
 * landing aggregates both; the room detail page only counts reviews
 * whose room_id matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('room_id')->nullable()->after('property_id')->constrained()->nullOnDelete();
            $table->index(['room_id', 'is_displayed']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropForeign(['room_id']);
            $table->dropIndex(['room_id', 'is_displayed']);
            $table->dropColumn('room_id');
        });
    }
};
