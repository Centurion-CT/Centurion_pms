<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Keverna Stays marketplace — property-level opt-out toggle + a
 * globally-unique public slug.
 *
 * Distinct from the two flags that already live on properties:
 *   - `slug`     : tenant-scoped (unique per tenant, e.g. "main")
 *   - `is_live`  : whether the tenant's OWN subdomain landing is
 *                  published, i.e. `<slug>.keverna.com`
 *
 * `is_listed_publicly` is the marketplace consent bit: "may Keverna
 * surface this property on keverna.com/stays and take card bookings
 * on the property's behalf." Default true so existing tenants are
 * onboarded to the marketplace by default (opt-out, per the product
 * decision). Operators flip it off from Settings > Property.
 *
 * `public_slug` is what appears in the marketplace URL:
 *   keverna.com/stays/{public_slug}
 * It has to be globally unique because it lives under the central
 * domain, not a per-tenant subdomain. Backfilled from the property
 * name with numeric disambiguation on collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->boolean('is_listed_publicly')->default(true)->after('is_live');
            $table->string('public_slug', 96)->nullable()->after('is_listed_publicly');
        });

        // Backfill public_slug from name, deduping globally.
        $used = [];
        DB::table('properties')->orderBy('id')->each(function (object $row) use (&$used): void {
            $base = Str::slug((string) $row->name) ?: 'stay';
            $candidate = $base;
            $n = 2;
            while (isset($used[$candidate])) {
                $candidate = $base.'-'.$n;
                $n++;
            }
            $used[$candidate] = true;
            DB::table('properties')->where('id', $row->id)->update(['public_slug' => $candidate]);
        });

        Schema::table('properties', function (Blueprint $table): void {
            $table->string('public_slug', 96)->nullable(false)->change();
            $table->unique('public_slug', 'properties_public_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropUnique('properties_public_slug_unique');
            $table->dropColumn(['is_listed_publicly', 'public_slug']);
        });
    }
};
