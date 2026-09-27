<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packages selected on a booking. Snapshots `name`, `unit_price`, and
 * `is_complimentary` at the moment of selection so later catalog edits
 * (rename, price change, complimentary → paid) don't silently rewrite
 * existing bookings.
 *
 * `added_at` distinguishes packages picked during the initial booking
 * (rolled into `bookings.packages_total` and paid via Paystack at
 * checkout) from packages added later via the guest portal (each ride
 * on a `BookingCharge` row referenced through `booking_charge_id`, so
 * the existing balance-payment flow handles settlement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_package', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            // nullOnDelete: catalog soft-delete leaves the historical line
            // intact; the snapshot columns render the original name/price.
            $table->foreignId('package_id')->nullable()
                ->constrained('packages')->nullOnDelete();

            $table->string('name_snapshot', 120);
            $table->unsignedBigInteger('unit_price_snapshot');
            $table->boolean('is_complimentary_snapshot');

            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('line_total');

            // 'during_booking' | 'post_booking'
            $table->string('added_at', 16);

            // For paid post-booking additions: the BookingCharge that
            // carries the line into the outstanding balance.
            $table->foreignId('booking_charge_id')->nullable()
                ->constrained('booking_charges')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_package');
    }
};
