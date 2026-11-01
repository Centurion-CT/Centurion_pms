<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Each business's own booking-site title (docs/MULTITENANCY.md, step 6),
 * replacing the install-wide BRAND_SITE_TITLE. Empty means "use the
 * business name". The first business keeps BRAND_SITE_TITLE if set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('site_title', 120)->nullable()->after('name');
        });

        $legacy = trim((string) env('BRAND_SITE_TITLE', ''));
        $first = DB::table('tenants')->orderBy('id')->value('id');
        if ($legacy !== '' && $first !== null) {
            DB::table('tenants')->where('id', $first)->update(['site_title' => $legacy]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('site_title');
        });
    }
};
