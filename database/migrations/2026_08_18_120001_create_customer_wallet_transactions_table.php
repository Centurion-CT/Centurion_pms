<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable ledger of every guest-wallet movement. Append-only —
 * mistakes are fixed via an Adjustment row, not by editing history.
 *
 * `meta.source` is the human-readable subtype (cash_topup,
 * overpayment_at_checkin, overstay_auto, pay_booking, pay_pos_sale,
 * settle_charges, cash_refund, admin_reconcile) so the wallet UI can
 * badge each row without inventing a new enum case for every reason.
 *
 * Nullable pointer columns link a transaction back to whatever object
 * caused it — the invoice PDF joins on these to show "settled booking
 * KV-XXX" instead of just a naked amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // What kind of ledger entry (topup / spend / refund / adjustment).
            $table->string('type', 16);

            // Signed. + for inflows (topup, refund, positive adjust),
            // − for outflows (spend, negative adjust).
            $table->bigInteger('amount_naira');
            // Snapshot so an audit doesn't need to replay history.
            $table->unsignedBigInteger('balance_after_naira');

            $table->string('description', 240)->nullable();
            $table->json('meta')->nullable();

            // Optional back-links to whatever caused this ledger row.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pos_sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            // For cash top-ups / cash refunds — matches Payment.method
            // strings ('cash', 'transfer', 'pos', 'other').
            $table->string('payment_method', 24)->nullable();
            $table->string('reference_note', 240)->nullable();

            // Manager who approved a refund (null for topup / spend /
            // system adjustments). Enforced in RefundFromWallet.
            $table->foreignId('approved_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();

            // Who caused this — polymorphic so an Operator, the Console
            // scheduler, or an Admin all fit.
            $table->string('causer_type', 80)->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'type']);
            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_wallet_transactions');
    }
};
