<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A POS transaction. `reference` is a short human-readable code stamped
 * at creation (KV-BAR-abcd) so operators can call it out during
 * disputes / cash-drawer reconciliation without exposing raw ids.
 *
 * Settlement:
 *   - booking_id set   → charge-to-room; downstream code creates a
 *                        BookingCharge tied to this sale.
 *   - booking_id null  → walk-in; Payment row records the tender.
 *   - customer_id      → optional; captures a regular walk-in
 *                        against the CRM.
 *
 * Draft sales don't touch stock or payments; only Completed sales do.
 * Void snapshots `voided_at` + `voided_reason` so the ledger tells
 * the full story without a separate audit table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('reference', 24);
            $table->string('status', 16)->default('draft');       // PosSaleStatus enum value
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('tax_amount')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('voided_reason', 240)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['outlet_id', 'status', 'sold_at']);
            $table->index(['booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};
