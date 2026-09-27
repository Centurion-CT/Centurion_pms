<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Durable record of every phone paired to the POS via the QR "link
 * device" flow. One row per phone. `token_hash` is the sha256 of the
 * secret stored in the operator's session on the phone — the middleware
 * looks the row up on every request and boots the phone if revoked.
 *
 * Kept separate from pos_pairing_codes because the lifecycles differ:
 * pairing codes are ephemeral (~90s), device sessions are audit records
 * managers see forever unless they delete an operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('device_label', 120);
            $table->string('user_agent', 512)->nullable();
            $table->string('paired_ip', 45)->nullable();
            $table->timestamp('paired_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
            $table->index(['tenant_id', 'operator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_device_sessions');
    }
};
