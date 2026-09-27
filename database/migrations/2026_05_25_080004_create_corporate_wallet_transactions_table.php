<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable ledger of every wallet movement. Append-only — corrections
 * happen via Adjustment rows, not edits, so audit always reconstructs.
 *
 * `meta` carries the originating booking ref, member, etc — used by
 * the admin transaction list to give context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('corporate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('corporate_member_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 16); // WalletTransactionType
            // Signed amount: positive for inflows (topup/release/refund/positive adjustment),
            // negative for outflows (hold/spend/negative adjustment).
            $table->bigInteger('amount_naira');

            // Snapshots so an audit doesn't need to replay history.
            $table->unsignedBigInteger('balance_after_naira');
            $table->unsignedBigInteger('held_after_naira');

            $table->string('description', 240)->nullable();
            $table->json('meta')->nullable();

            // For top-ups via Paystack — reconcile against the gateway.
            $table->string('paystack_reference', 64)->nullable();

            // Who caused this (Admin / CorporateMember / Operator / system).
            $table->string('causer_type', 80)->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();

            $table->timestamps();

            $table->index(['corporate_id', 'created_at']);
            $table->index(['corporate_id', 'type']);
            $table->index('paystack_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_wallet_transactions');
    }
};
