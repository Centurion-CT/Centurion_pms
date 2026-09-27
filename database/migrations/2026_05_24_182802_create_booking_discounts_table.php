<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per discount application. A single booking can in principle
 * carry multiple discounts (Phase 2 stacking), but Phase 1 enforces
 * one-per-booking at the application layer.
 *
 * `amount_off` is the resolved ₦ value the discount produced for THIS
 * booking — denormalised so we don't have to re-evaluate the discount
 * rules retroactively when computing booking totals.
 *
 * `code_used` captures whatever the operator/guest typed (case-preserved)
 * even for auto-apply discounts (null). Useful for support: "we
 * applied SUMMER25" vs "auto-applied festive special".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applied_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();

            $table->unsignedBigInteger('amount_off');           // ₦ off this booking
            $table->string('code_used', 32)->nullable();        // null = auto-apply
            $table->boolean('auto_applied')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'booking_id']);
            $table->index('discount_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_discounts');
    }
};
