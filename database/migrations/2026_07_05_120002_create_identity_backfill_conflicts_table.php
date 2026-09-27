<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit table for the Phase 1 operator-to-identity backfill.
 *
 * Today `operators.email` is globally unique so we expect zero rows at
 * cutover. The table is here as a safety net: (a) future backfills of
 * legacy CSVs, (b) if the operator uniqueness constraint is relaxed to
 * `(tenant_id, email)` per the roadmap note in the operators migration,
 * (c) guest-side merges in Phase 2 that trip on ambiguous email owners.
 *
 * Rows sit here until ops resolves them and sets `resolved_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_backfill_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            // The set of source-row ids that collided on this email. We
            // don't FK this — the source rows may be operators today and
            // customers in Phase 2, so we keep it as an opaque JSON list.
            $table->json('conflicting_source_ids');
            // 'operator' | 'customer' — which backfill produced the row.
            $table->string('source_kind', 32);
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['source_kind', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_backfill_conflicts');
    }
};
