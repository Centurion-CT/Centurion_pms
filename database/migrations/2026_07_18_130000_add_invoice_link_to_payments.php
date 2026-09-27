<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wires invoices into the existing payments table so manual receipts
 * (cash / transfer at front desk) live in the same ledger as Paystack
 * transactions.
 *
 * `paystack_reference` stays required + unique — RecordInvoicePayment
 * synthesises a `manual-<ulid>` string for its rows so the constraint
 * is satisfied without touching Paystack flow.
 *
 *  - invoice_id     Nullable link to the invoice this payment settles.
 *                   Null for pre-invoice Paystack payments (the majority
 *                   of the historical set).
 *  - method         Human-readable channel: paystack / cash / transfer /
 *                   card / other. Nullable so old rows stay untouched;
 *                   application layer treats null as 'paystack'.
 *  - reference_note Free-form staff note (bank txn id, receipt number).
 *                   Only used on manual (non-Paystack) rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->nullable()->after('booking_id')
                ->constrained()->nullOnDelete();
            $table->string('method', 20)->nullable()->after('type');
            $table->string('reference_note', 120)->nullable()->after('method');

            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['invoice_id']);
            $table->dropIndex(['invoice_id']);
            $table->dropColumn(['invoice_id', 'method', 'reference_note']);
        });
    }
};
