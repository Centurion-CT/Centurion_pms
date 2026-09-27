<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Outlet-initiated stock requests. Outlet operators file a request
 * from their daily sheet; the store keeper approves (routing through
 * RecordStockIssuance for the paired ledger write) or rejects with a
 * note. Outlet inventory has no direct-adjust action — requests are
 * the one-way street.
 *
 * `product_id` points at the OUTLET product being requested. The
 * source store product is resolved on approve by (tenant, store_id,
 * name) match — no explicit catalog mapping for MVP.
 *
 * `approved_quantity` may differ from `quantity` (keeper can revise
 * up or down before approving). `issued_pair_movement_id` cross-
 * links to the outlet-side StockMovement so the request → issuance
 * chain is walkable from either end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('approved_quantity')->nullable();
            $table->foreignId('requested_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->foreignId('resolved_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->text('requested_note')->nullable();
            $table->text('resolved_note')->nullable();
            $table->unsignedBigInteger('issued_pair_movement_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requests');
    }
};
