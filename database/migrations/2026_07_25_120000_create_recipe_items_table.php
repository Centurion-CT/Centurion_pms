<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bill-of-materials rows — a composite product (e.g. a cocktail) is
 * made from N units of one or more component products (e.g. a shot
 * of rum + a can of coke). Selling one composite decrements each
 * component by `quantity` × sale-line quantity.
 *
 * Composites usually set `track_stock=false` because their availability
 * is derived from components. Keeping them separate makes reporting
 * per-cocktail revenue straightforward.
 *
 * `quantity` is integer — track components in the smallest unit you
 * actually sell (shots, cans, servings). Fractional inventory is out
 * of scope; there's no partial-unit stock in the ledger anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();          // composite
            $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['product_id', 'component_product_id']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
