<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per-property portal QR — one printable QR per branch (typically
 * hung in the lobby / on room-service menus). Replaces the older
 * per-room QR shipped in 2026_09_05_130000 which required operators
 * to print + laminate one per room.
 *
 * The per-room qr_slug column on rooms stays put — existing
 * printed QRs still route through /room/{slug} for backwards compat
 * (see routes/tenant.php). New QRs point at /portal/{portal_qr_slug}
 * and the guest supplies their room number at auth time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('portal_qr_slug', 32)->nullable()->after('slug');
        });

        // Backfill: give every existing property a random slug.
        DB::table('properties')->orderBy('id')->each(function ($row): void {
            DB::table('properties')->where('id', $row->id)->update([
                'portal_qr_slug' => Str::lower(Str::random(24)),
            ]);
        });

        Schema::table('properties', function (Blueprint $table): void {
            $table->string('portal_qr_slug', 32)->nullable(false)->change();
            $table->unique('portal_qr_slug', 'properties_portal_qr_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropUnique('properties_portal_qr_slug_unique');
            $table->dropColumn('portal_qr_slug');
        });
    }
};
