<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking charges — extras the guest accumulates after the initial
 * reservation is created. Extension nights, charge-to-room (food, drink,
 * services), incidentals.
 *
 * The booking's outstanding balance at any point is:
 *
 *   booking.balance_due (initial deposit/hold remainder)
 *   + SUM(booking_charges.amount WHERE settled = false)
 *
 * Each charge can be settled independently (operator collects cash or
 * card at the front desk) — we don't try to be a full POS, just track
 * what the guest still owes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();

            // Categorises the charge for filtering + reporting:
            // extension, room_service, food_beverage, laundry, transport,
            // damage, deposit_forfeit, other. Free-form `type` keeps the
            // schema flexible (operators surface new categories without a
            // migration); the enum on the PHP side validates writes.
            $table->string('type', 32);

            // Operator-authored description shown on the receipt.
            $table->string('description', 240);

            // Integer NGN — matches the rest of the money columns.
            $table->bigInteger('amount');

            // Settlement state. settled=true means the guest paid this
            // line (cash, card, or off-platform). The operator chooses
            // *when* the charge is settled — we don't auto-settle on
            // checkout because some properties carry tabs forward.
            $table->boolean('settled')->default(false);
            $table->timestamp('settled_at')->nullable();
            $table->string('settled_method', 32)->nullable(); // cash / card / transfer / paystack / other

            $table->timestamps();

            $table->index(['tenant_id', 'booking_id']);
            $table->index(['tenant_id', 'settled']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_charges');
    }
};
