<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seasonal nightly rate overrides (PRD §9 / §6.3.2). Each row replaces the
 * Room's `base_rate` for the inclusive date range [start_date, end_date]
 * (both DATE columns, no time component). Per-date conflict detection is
 * enforced at the application layer — overlapping ranges on the same room
 * are rejected at save time.
 *
 * `label` exists for the operator's own bookkeeping (e.g. "Festive Season",
 * "Easter Weekend"); it's never shown to guests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('rate'); // NGN
            $table->string('label', 80)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'room_id', 'start_date', 'end_date'], 'room_prices_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_prices');
    }
};
