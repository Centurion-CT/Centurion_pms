<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A corporate reservation is a stay-window allocation for a corp at a
 * specific branch. "Acme Corp expecting 3 staff at the Abuja property
 * from Sep 15 to Sep 18" is one row here, with 3 CorporateReservation-
 * Member children + one or more CorporateReservationCategory rows
 * (e.g. "2 Standard rooms + 1 Suite").
 *
 * Actual bookings still get created via the normal path when the
 * guests arrive; the reservation ties them to the pre-agreed
 * allocation for reporting + front-desk visibility ("this walk-in
 * is expected from Acme").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('corporate_id')->constrained('corporates')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status', 24)->default('pending');   // pending|active|closed|cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'corporate_id']);
            $table->index(['tenant_id', 'property_id', 'starts_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_reservations');
    }
};
