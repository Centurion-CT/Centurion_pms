<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived codes that pair a fresh phone with an already-signed-in
 * operator. Flow:
 *   1. Operator hits "Link phone" on the web staff/devices page while
 *      signed in with the `web` session guard.
 *   2. Server creates a MobilePairingCode row (operator_id + tenant_id
 *      captured from the session, expires in 5 min) + shows it as a
 *      QR to scan.
 *   3. Fresh app scans, POSTs to /api/mobile/v1/pair with the code +
 *      device fingerprint (platform, name, app_version, push_token).
 *   4. Server validates + consumes the code, creates the MobileDevice
 *      + a Sanctum personal access token, returns the token to the app.
 * Codes are one-shot (consumed_at set) — same code can't be reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('code', 16);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by_mobile_device_id')->nullable()
                ->constrained('mobile_devices')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_pairing_codes');
    }
};
