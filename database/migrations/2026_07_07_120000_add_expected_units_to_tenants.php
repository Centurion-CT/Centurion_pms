<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captures the tenant's intended scale at self-serve signup. The value
 * is what the operator told us they'll list — not a count of actual
 * Room/Desk/Unit rows (that's a computed number and diverges from
 * intent over time).
 *
 * Used by:
 *  - The Register flow, which derives the initial SubscriptionPlan tier
 *    from this number via SubscriptionPlan::fromUnitCount().
 *  - Billing at trial conversion, which quotes the tier the operator
 *    signed up under (not a re-derived number from live inventory).
 *
 * Nullable so existing rows (pre-tier-system) continue to work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedSmallInteger('expected_units')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('expected_units');
        });
    }
};
