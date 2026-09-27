<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per PRD §9. One row per tenant for v1 (single-property accounts) — v2's
 * multi-property roadmap item just relaxes that one-to-one to one-to-many.
 *
 * Image relations (cover_image_id, gallery) land in §8 (Room Media) where
 * Spatie Media Library is wired in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // serviced | hotel | short_let | long_stay (PRD §6.1.1)
            $table->string('type', 32);
            $table->text('description')->nullable();
            $table->string('address');
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->boolean('is_live')->default(false)->index();
            $table->json('amenities')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
