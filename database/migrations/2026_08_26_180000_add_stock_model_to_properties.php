<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property-level toggle for how inventory is run.
 *
 *   store_and_requisition (default) — the current two-tier flow.
 *   per_department                  — each outlet manages its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('stock_model', 32)->default('store_and_requisition')
                ->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('stock_model');
        });
    }
};
