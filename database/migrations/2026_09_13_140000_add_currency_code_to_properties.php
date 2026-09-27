<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-property currency for the multi-currency rollout. Amounts stay
 * stored as integer whole units — no FX conversion — but every
 * ₦ hardcoded in the UI now resolves to the property's currency at
 * render time.
 *
 * Backfilled to NGN so existing properties keep their historical
 * display behaviour. Nullable so schema-level default doesn't hide
 * a genuine unset (e.g. the setup wizard leaves it blank until the
 * operator picks) — Money::resolve() coalesces to NGN when null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('currency_code', 3)->nullable()->after('country');
        });

        // Backfill every existing property to NGN. Anything created
        // post-migration goes through the setup / settings picker
        // which explicitly writes the choice.
        \Illuminate\Support\Facades\DB::table('properties')
            ->whereNull('currency_code')
            ->update(['currency_code' => 'NGN']);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('currency_code');
        });
    }
};
