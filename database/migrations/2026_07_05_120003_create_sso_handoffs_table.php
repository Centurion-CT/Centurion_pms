<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay-protection ledger for the central → tenant SSO handoff
 * (docs/unified-login.md §3 "SSO handoff", Phase 1c).
 *
 * Each row is a one-shot nonce. The handoff controller writes an
 * unconsumed row; the tenant-side consumer looks it up by nonce,
 * refuses if `consumed_at` is already set, then marks it consumed.
 *
 * The (identity, tenant, operator) triple is also stored so we have
 * an audit trail — who handed off to whom, when, and whether it was
 * ever used. Rows can be purged after `expires_at + 24h` by a scheduled
 * job (not built here — the table is small and cleanup can wait).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('nonce')->unique();
            $table->foreignId('identity_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_handoffs');
    }
};
