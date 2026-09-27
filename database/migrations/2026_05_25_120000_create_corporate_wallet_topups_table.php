<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks in-flight Paystack-funded wallet top-ups.
 *
 * Why a separate table rather than reusing `corporate_wallet_transactions`?
 *
 *  - The ledger is append-only with snapshotted balances. A top-up that's
 *    pending shouldn't touch the balance yet — but we need to remember the
 *    Paystack reference so the webhook can find it.
 *  - This lets us show the corporate admin "X pending" + retry/cancel
 *    unfinished checkouts.
 *
 * On webhook success we mark this row Succeeded and write the actual
 * Topup transaction to the ledger via WalletService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_wallet_topups', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('corporate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('corporate_member_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reference', 64)->unique();
            $t->unsignedBigInteger('amount_naira');
            $t->string('status', 16); // pending|succeeded|failed|cancelled
            $t->string('authorization_url', 500)->nullable();
            $t->string('note', 240)->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->timestamps();

            $t->index(['corporate_id', 'status']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_wallet_topups');
    }
};
