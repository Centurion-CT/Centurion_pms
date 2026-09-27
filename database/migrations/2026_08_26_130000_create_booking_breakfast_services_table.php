<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-day service marker for complimentary breakfast. A 3-night stay
 * with breakfast_included = true accrues 3 breakfast rows — one per
 * morning served. Kitchen ticks each row off as the guest is served.
 *
 * We store the actual served_at timestamp + the operator who served
 * (nullable — kitchen POS staff without user login could tick on a
 * shared terminal) so front desk can answer "did room 306 already
 * eat this morning?" and management gets a service-completeness report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_breakfast_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            // service_date = the morning served, YYYY-MM-DD.
            $table->date('service_date');
            $table->timestamp('served_at')->useCurrent();
            $table->foreignId('served_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamps();

            // One service row per (booking, morning) — the toggle is
            // idempotent so a double-click can't produce two rows.
            $table->unique(['booking_id', 'service_date'], 'bkg_breakfast_svc_unique');
            $table->index(['tenant_id', 'service_date'], 'bkg_breakfast_svc_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_breakfast_services');
    }
};
