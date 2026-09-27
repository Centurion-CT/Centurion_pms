<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discounts & coupons — operator-created promotional rules applied to
 * bookings.
 *
 * Distinction by `code`:
 *   - code IS NULL  → auto-apply discount (system finds & applies it
 *                     when conditions match, without operator/guest input)
 *   - code IS NOT NULL → coupon code (guest or operator enters it during
 *                     reservation)
 *
 * Targeting is per-room via the `discount_rooms` pivot — empty pivot
 * means "applies to every active room". Channel scoping is a JSON
 * array of BookingSource values; null = all channels.
 *
 * Validity is bounded by `starts_on` / `ends_on` (calendar dates the
 * stay must overlap with), and `min_nights` / `max_nights` (length of
 * the stay). A booking qualifies only if every set condition holds.
 *
 * `used_count` is denormalised so the engine can short-circuit when a
 * discount hits its `max_uses` cap without joining bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();

            // Operator-facing display name
            $table->string('name', 120);

            // Coupon code (uppercase, alphanumeric). NULL means auto-apply.
            // Uniqueness scoped per tenant so two tenants can have "SUMMER25".
            $table->string('code', 32)->nullable();

            $table->string('type', 16);                          // 'percent' | 'fixed'
            $table->unsignedInteger('value');                    // 0-100 for percent, ₦ for fixed

            // Stay-length window. Either nullable for "no constraint".
            $table->unsignedSmallInteger('min_nights')->nullable();
            $table->unsignedSmallInteger('max_nights')->nullable();

            // Validity window — checkin must be >= starts_on and < ends_on
            // (half-open, matches the AvailabilityBlock convention).
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Channels the discount is valid for. JSON list of BookingSource
            // values (e.g. ['online'], ['manual'], or ['online','manual']).
            // NULL = no restriction.
            $table->json('channels')->nullable();

            // Lifetime usage cap. NULL = uncapped.
            $table->unsignedInteger('max_uses')->nullable();

            // Running counter — incremented inside the same transaction
            // that applies the discount to a booking, so caps are race-safe.
            $table->unsignedInteger('used_count')->default(0);

            $table->boolean('active')->default(true);
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
            $table->index(['tenant_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
