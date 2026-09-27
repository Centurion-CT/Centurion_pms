<?php

declare(strict_types=1);

use App\Models\Property;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-property (branches) — every property gets a URL-safe slug
 * unique per tenant. Enables /branches/{slug} routing for the guest
 * side and per-branch pages in the dashboard.
 *
 * Existing single-property tenants get a slug of "main" — safe default
 * that reads well as a URL and stays stable across the rest of the
 * multi-branch work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('slug', 80)->nullable()->after('name');
        });

        // Backfill: every existing property gets a deterministic slug.
        // First property per tenant → "main"; any others (should be 0
        // pre-launch of this feature) get a name-derived slug.
        $seenByTenant = [];
        DB::table('properties')->orderBy('id')->each(function (object $row) use (&$seenByTenant): void {
            $existing = $seenByTenant[$row->tenant_id] ?? 0;
            $slug = $existing === 0
                ? 'main'
                : (Str::slug((string) $row->name) ?: 'branch').'-'.($existing + 1);
            DB::table('properties')->where('id', $row->id)->update(['slug' => $slug]);
            $seenByTenant[$row->tenant_id] = $existing + 1;
        });

        Schema::table('properties', function (Blueprint $table): void {
            $table->string('slug', 80)->nullable(false)->change();
            $table->unique(['tenant_id', 'slug'], 'properties_tenant_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropUnique('properties_tenant_slug_unique');
            $table->dropColumn('slug');
        });
    }
};
