<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest-initiated date modification requests (PRD §6.4.4). The booking row
 * does not change until the operator approves — these are operator-side
 * suggestions, not autonomous edits. Approval / rejection UI lives in §15
 * (operator dashboard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->date('requested_checkin');
            $table->date('requested_checkout');
            $table->text('guest_note')->nullable();
            // pending | approved | rejected
            $table->string('status', 16)->default('pending')->index();
            $table->text('operator_response')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_change_requests');
    }
};
