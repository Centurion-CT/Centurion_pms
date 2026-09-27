<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer interactions — the timeline of every touchpoint between a
 * property and a customer.
 *
 * Two distinct sources feed this:
 *   1. Operator-authored notes (call logged, email sent, etc.) — created
 *      from the CRM UI via the Show component's add-interaction form.
 *   2. Automatic system events (booking created, balance paid, no-show,
 *      review submitted) — emitted by booking actions and webhook
 *      handlers when they call CustomerLinker::recordEvent().
 *
 * `created_by_operator_id` is nullable because system events have no
 * authoring operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Optional link to the operator who logged it. Null = system event.
            $table->foreignId('created_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();

            // Optional link back to the booking that produced this event
            // (auto-events do, manual notes may not).
            $table->foreignId('booking_id')->nullable()
                ->constrained('bookings')->nullOnDelete();

            $table->string('type', 24);          // note / call / email / whatsapp / sms / in_person / system
            $table->string('direction', 16);     // inbound / outbound / none
            $table->string('subject', 200)->nullable();
            $table->text('body')->nullable();

            // For auto events: a stable system code we can match on
            // ("booking.created", "booking.confirmed", "booking.cancelled",
            // "balance.paid", "review.submitted"). Manual notes leave it null.
            $table->string('system_event', 60)->nullable();

            // When the touch actually happened (operator can backdate to
            // a phone call from yesterday). Defaults to created_at.
            $table->timestamp('happened_at')->index();

            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'happened_at']);
            $table->index(['tenant_id', 'system_event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_interactions');
    }
};
