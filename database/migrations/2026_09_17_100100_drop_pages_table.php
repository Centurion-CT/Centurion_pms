<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the operator-authored Pages CMS. The tenant subdomain root
 * now renders the marketplace stays detail view directly, so the
 * mini-CMS + `/p/{slug}` route + `is_home` override + shortcode
 * expansion have no purpose.
 *
 * Confirmed with the user (2026-09-17): all Page records are demo /
 * staging data. No down() — one-way delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pages');
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
