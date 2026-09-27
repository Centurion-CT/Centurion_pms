<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant guest wallet. One row per (tenant, customer). Balance is
 * the source of truth for "how much can this guest spend without
 * paying more cash". Every mutation goes through
 * {@see \App\Services\Customers\WalletService} so the ledger in
 * `customer_wallet_transactions` always reconstructs the balance.
 *
 * No held/reserved column — guest wallets are simpler than the B2B
 * CorporateWallet: money is either present or it isn't. Spends happen
 * synchronously at check-in, POS, and overstay deduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance_naira')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_wallets');
    }
};
