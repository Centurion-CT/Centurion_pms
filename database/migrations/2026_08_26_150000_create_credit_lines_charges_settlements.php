<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House-account style "credit line" — one customer owns a line;
 * one or more rooms are attached. Charges (food, drink, laundry,
 * etc) can be added against the line without collecting payment
 * upfront. Bills are settled offline in one or more payments
 * recorded against the line.
 *
 *   credit_lines           (customer + optional soft spending cap)
 *   credit_line_room       (many-to-many, room attach/detach)
 *   credit_line_charges    (per line-item, mirrors BookingCharge shape)
 *   credit_line_settlements (each offline payment applied to the line)
 *
 * outstanding = SUM(charges.amount) - SUM(settlements.amount)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // active | suspended | closed. Suspended blocks new charges
            // but keeps history queryable; closed also freezes settlement.
            $table->string('status', 16)->default('active');
            // Nullable = no cap. Soft cap: over-limit charges still land,
            // UI warns. See CreditLine::isOverLimit().
            $table->unsignedBigInteger('spending_limit')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'cl_tenant_status_idx');
            $table->index(['tenant_id', 'customer_id'], 'cl_tenant_customer_idx');
        });

        Schema::create('credit_line_room', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_line_id')->constrained('credit_lines')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->timestamp('attached_at')->useCurrent();
            $table->unique(['credit_line_id', 'room_id'], 'clr_unique');
            $table->index('room_id', 'clr_room_idx');
        });

        Schema::create('credit_line_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_line_id')->constrained('credit_lines')->cascadeOnDelete();
            // Which room the charge came from (optional — a manual
            // "unassigned" tab charge can be logged without a room).
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('type', 32);        // BookingChargeType-compatible string
            $table->string('description', 240);
            $table->unsignedBigInteger('amount');   // NGN
            $table->foreignId('added_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'credit_line_id'], 'clc_tenant_line_idx');
        });

        Schema::create('credit_line_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_line_id')->constrained('credit_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');   // NGN
            $table->string('method', 24);           // cash | transfer | card | other
            $table->string('reference_note', 240)->nullable();
            $table->foreignId('recorded_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamp('settled_at')->useCurrent();
            $table->timestamps();
            $table->index(['tenant_id', 'credit_line_id'], 'cls_tenant_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_line_settlements');
        Schema::dropIfExists('credit_line_charges');
        Schema::dropIfExists('credit_line_room');
        Schema::dropIfExists('credit_lines');
    }
};
