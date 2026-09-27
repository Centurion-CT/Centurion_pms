<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct FK from an invoice to the Corporate it should bill (denormalised
 * from booking.corporate_id so the Corporate Ledger page can list every
 * corp invoice with a single indexed query, and so a future
 * "consolidated invoice covering many bookings" doesn't need to walk
 * bookings just to know the corp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('corporate_id')->nullable()
                ->after('customer_id')
                ->constrained('corporates')->nullOnDelete();
            $table->index(['tenant_id', 'corporate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'corporate_id', 'status']);
            $table->dropForeign(['corporate_id']);
            $table->dropColumn('corporate_id');
        });
    }
};
