<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Length-of-stay threshold on a corporate rate. When set, the rate
 * only kicks in for stays >= min_nights. Powers negotiated LoS
 * discounts like "20% off if the stay is 7 nights or more" without a
 * separate table — a Global-scoped rate with rate_type=PercentOff +
 * min_nights=7 does the job and plugs into the existing rate
 * resolution walk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporate_rates', function (Blueprint $table): void {
            $table->unsignedSmallInteger('min_nights')->nullable()
                ->after('rate_value');
            $table->index(['corporate_id', 'min_nights']);
        });
    }

    public function down(): void
    {
        Schema::table('corporate_rates', function (Blueprint $table): void {
            $table->dropIndex(['corporate_id', 'min_nights']);
            $table->dropColumn('min_nights');
        });
    }
};
