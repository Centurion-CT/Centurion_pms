<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-operator JSON preferences bag. Started for the sidebar-style
 * pick (tiles vs list) — kept as a JSON column so future prefs
 * (density, colour theme, default landing page, etc.) can slot in
 * without another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->json('preferences')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
