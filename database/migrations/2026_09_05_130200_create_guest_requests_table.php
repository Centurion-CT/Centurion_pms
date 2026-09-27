<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests raised by a guest from the in-room QR portal — anything
 * that can't be self-served immediately (or that needs staff
 * attention) queues here for the operator dashboard.
 *
 * Kinds (see App\Enums\GuestRequestKind):
 *   - complaint    — free text, urgent-flag on the operator queue
 *   - taxi         — pickup time + destination
 *   - transfer     — request to change rooms (operator quotes any
 *                    price difference, then executes RoomReassign)
 *   - order        — first-pass "room service" placeholder; full
 *                    POS integration is a follow-up
 *   - custom       — anything else, free text
 *
 * `body` is a JSON blob for kind-specific fields (destination for
 * taxi, target category for transfer, etc.); `notes` is the guest's
 * free-text add-on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('room_id');
            $table->string('kind', 24);
            $table->string('status', 24)->default('open');
            $table->text('notes')->nullable();
            $table->json('body')->nullable();
            $table->unsignedBigInteger('resolved_by_operator_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('booking_id');
            $table->index('room_id');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_requests');
    }
};
