<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot linking a central IdentityAccount to a per-tenant Customer row
 * (docs/unified-login.md §3, Phase 2). A guest who has booked at three
 * tenants gets three link rows, all pointing at the same identity.
 *
 * `(tenant_id, customer_id)` is unique — a Customer row is claimed by
 * at most one identity. Nothing prevents the same identity from later
 * claiming rows on additional tenants — each just adds a new row.
 *
 * `claimed_at` is the point-in-time the guest verified ownership of
 * this Customer via the magic-link claim flow. Used for audit; not for
 * gating access (the presence of the row IS the access).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_guest_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identity_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id'], 'igl_tenant_customer_unique');
            $table->index('identity_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_guest_links');
    }
};
