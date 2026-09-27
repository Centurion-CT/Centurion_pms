<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guest raises a card-payable intent from the in-room portal
 * (extend stay, order, reserve another room). We create a Payment
 * (Pending), a Paystack transaction, AND this Intent row that
 * carries the "what to do on success" payload. The callback URL /
 * webhook resolves the intent, marks the Payment Succeeded, and
 * runs the action inline (extend the booking, create the PosSale,
 * confirm the reserved Booking).
 *
 * Keeping the intent OUT of the Payment row means:
 *   - Payment stays a pure money ledger (no polymorphic mush).
 *   - A follow-up feature that adds a new purpose (spa booking,
 *     late-checkout upgrade, etc.) is a new intent kind, not a
 *     new Payment column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_portal_intents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('purpose', 24);   // extend | order | reserve
            $table->string('status', 24)->default('pending');
            $table->json('payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('booking_id');
            $table->index('payment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_portal_intents');
    }
};
