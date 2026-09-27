<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row singleton that caches platform-wide aggregates (currently
 * total naira processed + 24-hour rolling window). Marketing pages
 * read from here on every request so we never scan the (soon multi-
 * million-row) payments table inline. Refreshed by:
 *   - php artisan keverna:recompute-platform-stats
 *   - Scheduler runs the command every 30 minutes
 *   - DatabaseSeeder seeds an initial value from historical Payments
 *
 * Kept minimal deliberately (id = 1, no tenant column) so scanning is
 * a single primary-key lookup regardless of how the platform grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_stats', function (Blueprint $table): void {
            $table->id();
            // Cumulative successful naira processed across every
            // tenant, since the beginning of the platform.
            $table->unsignedBigInteger('total_processed_naira')->default(0);
            // Rolling 24-hour window of successful naira — feeds the
            // per-second tick rate on the marketing kobo counter.
            $table->unsignedBigInteger('last_24h_naira')->default(0);
            // Stamp so the marketing view can report freshness and the
            // command can skip if it ran recently.
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });

        // Seed the singleton row so the app never has to guard against
        // an empty table. Values start at zero + get refreshed by the
        // recompute command on the next scheduler tick / manual invoke.
        \DB::table('platform_stats')->insert([
            'id' => 1,
            'total_processed_naira' => 0,
            'last_24h_naira' => 0,
            'computed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_stats');
    }
};
