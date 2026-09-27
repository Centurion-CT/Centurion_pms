<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping supply extensions on `products`.
 *
 *   usage_per_clean   how many units the product consumes per room turnover
 *                     (0 = never auto-deducts; positive = subtract N per
 *                     room on LogHousekeepingTurnover)
 *   is_rotational     true for durables that leave dirty → laundry → return
 *                     clean (bedsheets, towels). False for consumables
 *                     (soaps, shampoos, toothbrushes)
 *   qty_in_use        rotational SKUs only: units currently deployed in
 *                     rooms
 *   qty_at_laundry    rotational SKUs only: units currently at the laundry
 *
 *   quantity_on_hand  keeps its existing meaning — for rotational SKUs it
 *                     represents "clean stock on the shelf, ready to
 *                     deploy". Sum of three columns = total linens the
 *                     property owns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('usage_per_clean')->default(0)->after('low_stock_threshold');
            $table->boolean('is_rotational')->default(false)->after('usage_per_clean');
            $table->unsignedInteger('qty_in_use')->default(0)->after('is_rotational');
            $table->unsignedInteger('qty_at_laundry')->default(0)->after('qty_in_use');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['usage_per_clean', 'is_rotational', 'qty_in_use', 'qty_at_laundry']);
        });
    }
};
