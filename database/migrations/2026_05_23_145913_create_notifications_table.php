<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's default database notifications table (PRD §17) with two
 * Keverna-specific additions baked in:
 *
 *   - `tenant_id` so the bell-icon Livewire never has to join on
 *     `notifiable`. Auto-stamped by the Notification's `toDatabase` method
 *     when the notifiable is an Operator.
 *   - Index on `(notifiable_type, notifiable_id, read_at)` matches the
 *     query the bell uses to fetch unread counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
