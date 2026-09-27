<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-property — remember which branch the operator was last
 * working in so the dashboard reopens where they left off. Session
 * fallback: PropertyContext falls back to first property when this
 * column is null OR the referenced property was deleted / re-assigned
 * to another tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_property_id')->nullable()->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('current_property_id');
        });
    }
};
