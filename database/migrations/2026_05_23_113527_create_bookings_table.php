<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per PRD §9 / §6.4.2. One row per guest booking; the room nights it occupies
 * live in `availability_blocks` (one row per night, FK back to bookings).
 *
 * Money columns are integer NGN throughout (matches PricingCalculator output).
 *
 * `payment_status` is a denormalised projection of the related Payments — kept
 * here for fast filters in the operator's dashboard without joining.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();

            // Human-friendly KV-XXXXXXXX reference shown to the guest.
            $table->string('booking_ref', 16)->unique();

            // Guest details (PRD §6.4.2)
            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_phone', 32);
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->text('special_requests')->nullable();

            // Stay window — DATE columns, half-open [checkin, checkout).
            $table->date('checkin_date');
            $table->date('checkout_date');
            $table->unsignedSmallInteger('nights');

            // Money — sourced from PricingBreakdown.
            $table->unsignedInteger('room_total');     // pre-fee, post-discount (== operator_payout)
            $table->unsignedInteger('paystack_fee');
            $table->unsignedInteger('keverna_fee');
            $table->unsignedInteger('total_amount');   // what the guest pays
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->unsignedInteger('balance_due')->default(0);

            // Lifecycle
            $table->string('booking_status', 16)->default('pending')->index();
            $table->string('payment_status', 16)->default('pending')->index();
            $table->string('source', 16)->default('online');

            $table->timestamps();

            $table->index(['tenant_id', 'room_id', 'checkin_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
