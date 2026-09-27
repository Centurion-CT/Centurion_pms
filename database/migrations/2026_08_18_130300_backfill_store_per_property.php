<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill for the two-tier stock model.
 *
 * Provisions one Store ("Main store") per property. Nothing else —
 * the store starts empty; keepers create store Products as they
 * receive supplier deliveries. Existing outlet stock is untouched.
 *
 * Idempotent — safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $properties = DB::table('properties')->orderBy('id')->get();
        foreach ($properties as $property) {
            $exists = DB::table('stores')
                ->where('tenant_id', $property->tenant_id)
                ->where('property_id', $property->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $slug = $this->uniqueStoreSlug((int) $property->tenant_id, 'main-store');

            DB::table('stores')->insert([
                'tenant_id'   => $property->tenant_id,
                'property_id' => $property->id,
                'name'        => 'Main store',
                'slug'        => $slug,
                'notes'       => null,
                'active'      => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('stores')->where('name', 'Main store')->delete();
    }

    private function uniqueStoreSlug(int $tenantId, string $base): string
    {
        $base = Str::slug($base) ?: 'store';
        $candidate = $base;
        $i = 2;
        while (
            DB::table('stores')
                ->where('tenant_id', $tenantId)
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }
};
