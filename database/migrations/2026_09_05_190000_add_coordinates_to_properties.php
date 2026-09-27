<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical coordinates for a property — powers the "Where we are"
 * card on the guest self-service portal (embedded map + one-tap
 * open in the phone's native maps app). Both columns are nullable
 * so pre-migration properties keep working; the map section on
 * the portal is gated on both being set.
 *
 * decimal(10,7) → ~1cm precision, well past what a hotel needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('state');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
