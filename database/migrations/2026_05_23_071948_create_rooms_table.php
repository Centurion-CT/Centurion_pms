<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per PRD §9. One row per bookable unit. `type` is a free-form label (Suite,
 * Studio, Entire Unit, etc. — PRD §6.3.1) — we don't enum-constrain it so
 * boutique hotels and short-let hosts can use their own taxonomies without
 * shipping a release.
 *
 * Booking and pricing live in separate tables; this row stores DEFAULTS
 * (base_rate, monthly_rate, min_nights) that the booking engine applies
 * unless a RoomPrice override is in effect for the requested date range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Free-form label per PRD §6.3.1.
            $table->string('type', 64);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('capacity_adults');
            $table->unsignedSmallInteger('capacity_children')->default(0);
            // NGN, no fractional units (Naira sub-units are essentially unused).
            $table->unsignedInteger('base_rate');
            // Set only when booking_type allows long-stay (PRD §6.3.2).
            $table->unsignedInteger('monthly_rate')->nullable();
            $table->unsignedTinyInteger('min_nights')->default(1);
            // 'short_let' | 'long_stay' | 'both'
            $table->string('booking_type', 16);
            // 'active' = visible on booking engine, 'inactive' = hidden
            $table->string('status', 16)->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
