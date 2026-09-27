<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A sellable item in an outlet — a Guinness bottle, a plate of jollof,
 * a laundered shirt. Amounts are integer NGN (no minor units — Nigeria
 * doesn't use kobo in practice). `track_stock=false` covers service
 * items (laundry, spa treatments) that never decrement; those never
 * write stock_movements when sold.
 *
 * `quantity_on_hand` is a materialised cache — the stock_movements
 * ledger is the source of truth. RecordStockMovement keeps them in
 * sync inside a transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 60)->nullable();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('price');                              // sell price, NGN
            $table->unsignedInteger('cost')->default(0);                   // COGS, NGN
            $table->boolean('track_stock')->default(true);
            $table->integer('quantity_on_hand')->default(0);               // signed to catch bugs
            $table->unsignedInteger('low_stock_threshold')->default(0);    // 0 disables badge
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'outlet_id', 'sku']);
            $table->index(['tenant_id', 'outlet_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
