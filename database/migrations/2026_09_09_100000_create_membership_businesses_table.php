<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A membership "business" is a sub-service the hotel runs alongside
 * rooms, gym, spa, snooker, pool, etc. Each business hosts its own
 * plans + subscribers so the operator can price a Gym Monthly
 * separately from Spa Monthly and track their revenue independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_businesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()
                ->constrained('properties')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_businesses');
    }
};
