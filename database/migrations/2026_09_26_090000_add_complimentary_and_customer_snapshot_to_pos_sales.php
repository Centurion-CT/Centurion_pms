<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complimentary sales + tied-customer snapshot for POS.
 *
 * Two orthogonal concepts land here:
 *
 *   Complimentary
 *     - `pos_sales.is_complimentary` — whole sale was on the house
 *     - `pos_sales.complimentary_by_operator_id` — who authorised it
 *     - `pos_sales.complimentary_reason` — short paper-trail note
 *     - `pos_sale_lines.is_complimentary` — individual line comped
 *       (line stays on the sale for stock + reporting, but its
 *        subtotal drops to ₦0 in the running total)
 *
 *   Customer tie
 *     - `pos_sales.customer_id` already existed; we add two snapshot
 *       columns so walk-ins can still be attributed to a person
 *       without forcing a Customer profile create:
 *         customer_phone_snapshot / customer_name_snapshot
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->boolean('is_complimentary')->default(false)->after('total');
            $table->foreignId('complimentary_by_operator_id')
                ->nullable()
                ->after('is_complimentary')
                ->constrained('operators')
                ->nullOnDelete();
            $table->string('complimentary_reason', 200)->nullable()->after('complimentary_by_operator_id');
            $table->string('customer_phone_snapshot', 30)->nullable()->after('customer_id');
            $table->string('customer_name_snapshot', 120)->nullable()->after('customer_phone_snapshot');

            $table->index(['tenant_id', 'is_complimentary', 'sold_at'], 'pos_sales_complimentary_idx');
        });

        Schema::table('pos_sale_lines', function (Blueprint $table): void {
            $table->boolean('is_complimentary')->default(false)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_lines', function (Blueprint $table): void {
            $table->dropColumn('is_complimentary');
        });

        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->dropIndex('pos_sales_complimentary_idx');
            $table->dropConstrainedForeignId('complimentary_by_operator_id');
            $table->dropColumn([
                'is_complimentary',
                'complimentary_reason',
                'customer_phone_snapshot',
                'customer_name_snapshot',
            ]);
        });
    }
};
