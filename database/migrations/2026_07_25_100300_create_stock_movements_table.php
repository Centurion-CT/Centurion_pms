<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The source-of-truth ledger for every stock change. `delta` is signed
 * — negative for outflows (sold, wasted), positive for inflows
 * (received, adjusted up). `quantity_after` is a denormalised snapshot
 * of `products.quantity_on_hand` immediately after the movement,
 * captured inside the same transaction so the audit trail is
 * self-explanatory without joining across rows.
 *
 * Polymorphic `source_type` + `source_id` cover:
 *   - PosSale (a Sold movement — the sale is the source)
 *   - null (an operator-initiated Adjusted / Wasted / Received)
 *   - StockMovement (paired Transferred rows point at each other)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->string('reason', 24);                          // StockMovementReason enum value
            $table->integer('delta');                              // signed
            $table->integer('quantity_after');                     // snapshot post-movement
            $table->unsignedInteger('unit_cost')->nullable();      // captured on Received / InitialStock
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['product_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
