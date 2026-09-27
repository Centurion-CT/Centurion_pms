<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landing-page branding knobs the operator tweaks in the dashboard
 * (PRD §6.5.1). The dashboard UI ships in §16; these columns land now so
 * the public LandingController can consume them with sane defaults.
 *
 * section_visibility lets the operator hide rarely-used sections (reviews,
 * contact block, map, amenities) without losing their data — a soft delete
 * for entire UI blocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('primary_color', 7)->default('#1f6feb')->after('amenities');
            $table->string('tagline', 80)->nullable()->after('primary_color');
            // One of: 'inter_playfair', 'work_libre', 'manrope_lora', 'dm_sans_cormorant', 'space_grotesk_eb'
            $table->string('font_theme', 32)->default('inter_playfair')->after('tagline');
            // {reviews,contact_block,map,amenities} -> bool; defaults to all-visible
            $table->json('section_visibility')->nullable()->after('font_theme');
            $table->boolean('whatsapp_button_enabled')->default(false)->after('section_visibility');
            $table->string('whatsapp_button_prefill', 200)->nullable()->after('whatsapp_button_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn([
                'primary_color',
                'tagline',
                'font_theme',
                'section_visibility',
                'whatsapp_button_enabled',
                'whatsapp_button_prefill',
            ]);
        });
    }
};
