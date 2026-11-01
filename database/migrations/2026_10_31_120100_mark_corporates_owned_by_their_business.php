<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Corporates onboarded "centrally" by Keverna had no creating business
 * and were read-only to every business. In Centurion each corporate
 * belongs to one business (step 2), and there is no central admin, so
 * the business it belongs to may edit it (docs/MULTITENANCY.md, step 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('corporates')
            ->whereNull('created_by_tenant_id')
            ->whereNotNull('tenant_id')
            ->update(['created_by_tenant_id' => DB::raw('tenant_id')]);
    }

    public function down(): void
    {
        // Which rows were central isn't recorded; nothing to undo.
    }
};
