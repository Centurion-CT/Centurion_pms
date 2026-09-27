<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest invoices authored by front desk / property manager. One booking
 * can produce multiple invoices (mid-stay interim + final settlement).
 *
 * Snapshot columns (bank_*) capture the tenant's payout account AS OF
 * issue time — a later Paystack detail change must not rewrite invoices
 * the guest already holds.
 *
 * `sequence` is a monotonic per-tenant counter allocated inside a locked
 * transaction; `number` is the human-facing formatted string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('issued_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();

            // Per-tenant monotonic sequence. Allocated inside CreateInvoice
            // via a row-locked SELECT MAX(sequence)+1. Number is the
            // formatted display string (INV-2026-000042). Both are set
            // when the invoice moves out of Draft.
            $table->unsignedBigInteger('sequence')->nullable();
            $table->string('number', 40)->nullable();

            $table->string('status', 16)->default('draft');

            // Guest snapshot — copied from booking at draft time so a
            // later customer edit doesn't retroactively rewrite issued
            // invoices. Kept nullable so ad-hoc invoices (no booking)
            // can also live here later.
            $table->string('guest_name', 160)->nullable();
            $table->string('guest_email', 180)->nullable();
            $table->string('guest_phone', 40)->nullable();

            // Bank details snapshot — copied from the tenant's
            // paystack_* fields at draft time. If ops change the payout
            // account later, historical invoices still show what the
            // guest was told to pay into.
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_name', 160)->nullable();
            $table->string('bank_account_number', 40)->nullable();

            $table->text('notes')->nullable();

            // Integer NGN — matches every other money column in the schema.
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('total')->default(0);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'booking_id']);
            $table->index(['tenant_id', 'status']);
            // Sequence + number are only populated post-Draft, so unique
            // must be per-tenant-and-nullable-tolerant. PostgreSQL
            // treats NULL as distinct in unique indexes, which is what
            // we want here (many Drafts, at most one row per sequence).
            $table->unique(['tenant_id', 'sequence']);
            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
