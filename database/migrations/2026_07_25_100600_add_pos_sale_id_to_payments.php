<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Payments already carry booking_id + invoice_id (both nullable) —
 * this adds pos_sale_id so a POS-completed sale's tender is tracked
 * on the same ledger every other payment lives on. Nullable + FK-on-null
 * so pre-POS rows are unaffected. FinanceSnapshot's "Collected" query
 * (sums Succeeded payments in-window) picks up POS revenue with no
 * downstream code changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('pos_sale_id')->nullable()->after('invoice_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pos_sale_id');
        });
    }
};
