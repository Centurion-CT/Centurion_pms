<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who took the cash / swiped the card. Historically we only had the
 * activity_log entry ("Operator X recorded a payment") for audit,
 * which was fine for a receipt trail but painful for reports and the
 * payments-page UX (LEFT JOIN activity_log per row to display the
 * name is expensive + fragile).
 *
 * Nullable + on-delete-null so Paystack webhooks and historical rows
 * still work — those genuinely don't have a human collector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('collected_by_operator_id')
                ->nullable()
                ->after('method')
                ->constrained('operators')
                ->nullOnDelete();
            // Reports commonly slice by "who collected in this window",
            // so index it alongside paid_at.
            $table->index(['tenant_id', 'collected_by_operator_id', 'paid_at'],
                'payments_tenant_collector_paidat_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_tenant_collector_paidat_idx');
            $table->dropConstrainedForeignId('collected_by_operator_id');
        });
    }
};
