<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "charge to room" POS sale can now settle against a house-account
 * credit line instead of a specific booking folio. credit_line_id
 * lives alongside booking_id — at most one is set on any given sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->foreignId('credit_line_id')->nullable()->after('booking_id')
                ->constrained('credit_lines')->nullOnDelete();
            $table->index('credit_line_id', 'ps_credit_line_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->dropForeign(['credit_line_id']);
            $table->dropIndex('ps_credit_line_idx');
            $table->dropColumn('credit_line_id');
        });
    }
};
