<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room categories — the class/template a Room belongs to.
 *
 * Hotels typically have a small number of room types ("Deluxe",
 * "Suite", "Studio") and multiple physical units (Room 101..Room 105)
 * within each type. Pre-categories the schema collapsed those two
 * dimensions onto a single Room row, which made it tedious to manage
 * pricing changes that apply across every unit of the same class.
 *
 * RoomCategory owns the SHARED fields — name, type, description,
 * capacity, base/monthly rate, min nights, length-of-stay discounts,
 * deposit policy, booking type, gallery photos, house rules. The Room
 * row keeps only the per-unit fields: room_number, visibility status,
 * and internal notes.
 *
 * For backward compatibility we keep the equivalent columns on `rooms`
 * during the transition; Room model accessors read from the category
 * first, falling back to the room's own column when no category is
 * attached. Phase 2 will drop the redundant Room columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            // Operator-facing name + the "type" the booking engine
            // surfaces to guests ("Deluxe", "Suite", "Studio", etc).
            $table->string('name', 120);
            $table->string('type', 64);
            $table->text('description')->nullable();

            // Capacity defaults — every physical room in this category
            // inherits these unless we add a per-room override later.
            $table->unsignedSmallInteger('capacity_adults');
            $table->unsignedSmallInteger('capacity_children');

            // Pricing (NGN integers — matches existing money columns).
            $table->unsignedBigInteger('base_rate');
            $table->unsignedBigInteger('monthly_rate')->nullable();
            $table->unsignedSmallInteger('min_nights')->default(1);
            $table->unsignedSmallInteger('weekly_discount_percent')->default(0);
            $table->unsignedSmallInteger('monthly_discount_percent')->default(0);

            // Hold / deposit policy.
            $table->string('deposit_type', 16)->default('none');   // none / fixed / percent
            $table->unsignedBigInteger('deposit_value')->default(0);

            // Which booking modes this category supports.
            $table->string('booking_type', 16);                    // short_let / long_stay / both

            // Visibility on the booking engine. Distinct from
            // Room.status — a category can be Active even if a
            // specific room in it is Inactive (e.g. out for repair).
            $table->string('status', 16)->default('active');

            // House rules / policies shown to guests on the public
            // room detail page.
            $table->text('rules')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_categories');
    }
};
