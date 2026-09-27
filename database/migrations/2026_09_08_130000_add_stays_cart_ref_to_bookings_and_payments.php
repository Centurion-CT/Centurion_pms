<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keverna Stays multi-room cart: a shared reference stamped on every
 * Booking created in the same checkout AND on the single Payment
 * that charges the aggregate. Cart-aware confirmation walks the
 * sibling bookings via this ref so a single Paystack success
 * confirms all N rooms at once.
 *
 * Nullable so pre-existing single-room marketplace + tenant bookings
 * stay untouched; the column is only populated when the guest
 * checked out multiple rooms in one flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('stays_cart_ref', 40)->nullable()->after('booking_ref');
            $table->index('stays_cart_ref');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('stays_cart_ref', 40)->nullable()->after('paystack_reference');
            $table->index('stays_cart_ref');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['stays_cart_ref']);
            $table->dropColumn('stays_cart_ref');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['stays_cart_ref']);
            $table->dropColumn('stays_cart_ref');
        });
    }
};
