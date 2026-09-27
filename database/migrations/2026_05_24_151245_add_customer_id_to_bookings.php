<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links each booking to its CRM Customer record. Backfilled for
 * historical rows by the BackfillCustomersFromBookings command.
 *
 * Nullable because:
 *   - manual bookings without an email/phone (rare but legal)
 *   - the brief window between Booking::create() and CustomerLinker
 *     attaching the customer inside the same transaction
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('tenant_id')
                ->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
