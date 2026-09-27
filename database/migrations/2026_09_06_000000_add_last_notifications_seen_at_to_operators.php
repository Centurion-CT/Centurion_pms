<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks when the operator last cleared the notification bell.
 * The bell polls for guest requests + booking events created
 * AFTER this timestamp; clicking the bell (Mark all seen) bumps
 * it to now. Nullable — a null value means "never seen anything,
 * treat everything as new" so the first click clears the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->timestamp('last_notifications_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('last_notifications_seen_at');
        });
    }
};
