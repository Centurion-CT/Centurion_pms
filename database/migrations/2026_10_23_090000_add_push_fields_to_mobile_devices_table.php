<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host app push notifications (docs/host-app/PHASE-5.md):
 *  - app: which Flutter app the device row belongs to (host | staff).
 *    Only `host` rows receive host pushes; null = legacy / staff app.
 *  - push_platform: ios | android for the FCM token.
 *  - push_prefs: per-device toggles {bookings, payments, ...}; null = all on.
 *  - push_token_updated_at: when the token was last set or cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table): void {
            $table->string('app', 16)->nullable()->after('platform');
            $table->string('push_platform', 16)->nullable()->after('push_token');
            $table->json('push_prefs')->nullable()->after('push_platform');
            $table->timestamp('push_token_updated_at')->nullable()->after('push_prefs');
            $table->index('push_token');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table): void {
            $table->dropIndex(['push_token']);
            $table->dropColumn(['app', 'push_platform', 'push_prefs', 'push_token_updated_at']);
        });
    }
};
