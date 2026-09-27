<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot linking a central identity_account to a per-tenant Operator.
 *
 * `(identity_account_id, tenant_id)` is unique — one identity gets at
 * most one operator seat per tenant. `(tenant_id, operator_id)` is
 * unique — an Operator row is owned by exactly one identity.
 *
 * `last_login_at` is stored per-link (not just on the identity) so the
 * chooser can order cards by "the tenant you last opened," not by
 * "the last time you logged in overall."
 *
 * We do NOT snapshot role here — role lives on the Operator row and
 * is read through the relation. Storing it twice invites drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_operator_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identity_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['identity_account_id', 'tenant_id'], 'idl_identity_tenant_unique');
            $table->unique(['tenant_id', 'operator_id'], 'idl_tenant_operator_unique');
        });

        // Now that the target table exists, wire up the deferred FK from
        // identity_accounts.preferred_link_id → identity_operator_links.id.
        // ON DELETE SET NULL so removing a link doesn't destroy the parent
        // identity — the user just loses their "straight through" preference.
        Schema::table('identity_accounts', function (Blueprint $table): void {
            $table->foreign('preferred_link_id')
                ->references('id')->on('identity_operator_links')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('identity_accounts', function (Blueprint $table): void {
            $table->dropForeign(['preferred_link_id']);
        });

        Schema::dropIfExists('identity_operator_links');
    }
};
