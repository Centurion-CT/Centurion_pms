<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-sided conversation thread on a guest request. Front-desk
 * operator posts a message here → the guest sees it on the "My
 * requests" tile in the in-room QR portal, so the "we're on it"
 * / "your taxi is here" / "we've upgraded you, come to reception"
 * loop happens inside the same surface the guest raised the
 * request from.
 *
 * `sender_type` values: 'operator' | 'guest'. Guest-side sending
 * lives in the portal component; operator-side in the guest
 * requests dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_request_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('guest_request_id');
            $table->string('sender_type', 16);
            $table->unsignedBigInteger('sender_operator_id')->nullable();
            $table->text('message');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('guest_request_id');
            $table->index(['guest_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_request_messages');
    }
};
