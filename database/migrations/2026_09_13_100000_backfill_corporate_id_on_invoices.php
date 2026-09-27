<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copy corporate_id from bookings onto their existing invoices. The
 * denormalisation was added in 2026_09_12_180000 but rows created
 * between that migration and the CreateInvoice callers that were
 * writing the column got left with NULL — some callers were passing
 * stale Booking instances loaded before corporate_id was attached.
 * The Corporate Ledger UI now falls back to the booking join, but
 * the indexed corporate_id column is still preferred for hot paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')
            ->whereNull('corporate_id')
            ->whereNotNull('booking_id')
            ->update([
                'corporate_id' => DB::raw(
                    '(select corporate_id from bookings where bookings.id = invoices.booking_id)'
                ),
            ]);
    }

    public function down(): void
    {
        // Backfill only; not reversible.
    }
};
