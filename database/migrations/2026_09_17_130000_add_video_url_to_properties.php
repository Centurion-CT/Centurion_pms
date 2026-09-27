<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single YouTube URL the operator can drop in from Property Settings.
 * The stays detail page turns it into a click-to-play video card with the
 * property's gallery hero as the poster. Kept as a plain URL string so
 * the operator pastes what YouTube shows in the address bar, we extract
 * the video id at render time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('video_url', 400)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('video_url');
        });
    }
};
