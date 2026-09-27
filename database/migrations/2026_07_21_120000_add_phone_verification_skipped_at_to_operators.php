<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp delivery via Sendpipe can be down or the operator's number can be
 * unreachable, and we don't want that to keep them out of the dashboard.
 * This column records when they explicitly deferred the phone step so the
 * onboarding gate can let them through without falsely claiming the number
 * was verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->timestamp('phone_verification_skipped_at')->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('phone_verification_skipped_at');
        });
    }
};
