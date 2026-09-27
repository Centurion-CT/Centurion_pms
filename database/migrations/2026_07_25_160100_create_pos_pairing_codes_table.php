<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Short-lived one-shot codes bridging the desktop QR to the phone
 * pairing endpoint. When an operator taps "Link phone" on the POS,
 * we mint a row here with a ~90s expiry and encode the plaintext
 * nonce into the QR. When the phone hits /pos/pair/{code} we mark
 * claimed_at and mint a durable pos_device_sessions row.
 *
 * Kept separate from pos_device_sessions because the lifecycles are
 * different — pairing codes get pruned aggressively, device sessions
 * live for as long as the operator does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('device_session_id')->nullable()
                ->constrained('pos_device_sessions')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_pairing_codes');
    }
};
