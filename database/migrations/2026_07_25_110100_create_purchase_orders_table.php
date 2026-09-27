<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A purchase order — you commit to buying N units of X from Supplier Y.
 * `outlet_id` is the destination outlet so stock lands in the right
 * place when the delivery arrives.
 *
 * Status transitions:
 *   Draft → Sent (operator marks it as emailed / phoned in)
 *   Sent → PartiallyReceived (some qty landed, but not all)
 *   Sent | PartiallyReceived → Received (fully landed)
 *   Any of the above → Cancelled (never delivered)
 *
 * Amounts are integer NGN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->string('reference', 24);
            $table->string('status', 24)->default('draft');    // PurchaseOrderStatus enum
            $table->date('expected_at')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('tax_amount')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status']);
            $table->index(['supplier_id', 'status']);
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
