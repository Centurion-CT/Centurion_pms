<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff we're expecting on the reservation window. When a booking is
 * actually created for one of them (either by them online or at the
 * front desk on arrival) it lands here in `assigned_booking_id` so we
 * can tell "still expected" from "already arrived / attempted."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_reservation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_reservation_id')
                ->constrained('corporate_reservations')->cascadeOnDelete();
            $table->foreignId('corporate_member_id')
                ->constrained('corporate_members')->cascadeOnDelete();
            $table->foreignId('assigned_booking_id')->nullable()
                ->constrained('bookings')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['corporate_reservation_id', 'corporate_member_id'],
                'corp_res_mem_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_reservation_members');
    }
};
