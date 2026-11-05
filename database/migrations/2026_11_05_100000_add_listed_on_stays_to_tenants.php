<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A business's consent to appear on the Stays marketplace on the central
 * domain (docs/MULTITENANCY.md). Empty means not listed, so nobody is
 * listed until they choose to be. Separate from properties.is_listed_publicly,
 * Keverna's opt-out flag, which defaulted to on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('listed_on_stays_at')->nullable()->after('site_title');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('listed_on_stays_at');
        });
    }
};
