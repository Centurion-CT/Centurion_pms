<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manually-entered guest reviews (PRD §6.4.1). v1 has no automated review
 * collection (that lands in v2 per roadmap); operators paste in reviews
 * from their existing OTA listings or post-stay emails.
 *
 * `is_displayed` lets the operator hide a review without deleting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('guest_name', 80);
            $table->text('body');
            $table->unsignedTinyInteger('rating')->default(5); // 1..5
            $table->boolean('is_displayed')->default(true);
            $table->unsignedInteger('order_column')->default(0)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'property_id', 'is_displayed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
