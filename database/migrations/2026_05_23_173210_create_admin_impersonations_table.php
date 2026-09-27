<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot impersonation tokens issued by admins (PRD §18).
 *
 *   - `token_hash` stores sha-256(plaintext); plaintext appears once in
 *     the URL the admin copies. Mirrors guest_portal_tokens — same
 *     constant-time verification path.
 *   - `expires_at` defaults 15 minutes out; configurable per-issue.
 *   - `used_at` is set the first time the link is followed. Re-using
 *     a used token is rejected — single-use.
 *   - `operator_id` is the operator the admin landed as; null until the
 *     link is followed (we pick the first active operator on the tenant
 *     at consumption time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_impersonations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_impersonations');
    }
};
