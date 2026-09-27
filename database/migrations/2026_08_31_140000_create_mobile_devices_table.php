<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Flutter app install linked to an operator. The Sanctum
 * personal_access_tokens row for the phone is bound to the operator
 * (tokenable_type = Operator); this table tracks per-device metadata:
 * platform, human name, push token, last-seen. Revoking a device
 * flips revoked_at and cascades the token delete via the
 * `EnsureMobileDeviceActive` middleware (which fires on every mobile
 * request and 401s if the linked device is revoked). Same shape as
 * pos_device_sessions but for native apps talking to /api/mobile/v1
 * with a bearer token, not a cookie-authed web browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('platform', 16);          // 'ios' | 'android'
            $table->string('device_name', 120);      // 'Chidi's iPhone 15'
            $table->string('app_version', 32);       // '1.0.0+42'
            $table->string('push_token', 500)->nullable();
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
        Schema::dropIfExists('mobile_devices');
    }
};
