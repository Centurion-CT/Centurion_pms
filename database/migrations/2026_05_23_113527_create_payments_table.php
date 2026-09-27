<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Paystack transaction tied to a booking (PRD §9).
 *
 * A booking may have 1..N payments:
 *  - Full payment        -> 1 succeeded Full row
 *  - Hold + balance flow -> 1 succeeded Deposit row, later 1 succeeded Balance row
 *  - Failed retries      -> Pending/Failed rows precede the eventual succeeded one
 *
 * paystack_reference is unique so webhook replays are inert: ActivatePayment
 * sees the row already in Succeeded and no-ops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('paystack_reference')->unique();
            $table->unsignedInteger('amount'); // NGN — total guest paid (room + fees)
            $table->string('type', 16);        // PaymentType
            $table->string('status', 16)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
