<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('name'); // e.g. "Flat 3B, 12 Awolowo Road"
            $table->string('address');
            $table->string('city', 64)->nullable();
            $table->string('state', 64)->nullable();
            $table->unsignedSmallInteger('bedrooms')->default(1);
            $table->unsignedSmallInteger('bathrooms')->default(1);
            $table->unsignedInteger('size_sqm')->nullable();
            $table->unsignedInteger('monthly_rent_naira')->nullable();
            $table->unsignedInteger('annual_rent_naira');
            $table->unsignedInteger('deposit_naira')->default(0);
            $table->unsignedInteger('service_charge_annual_naira')->default(0);
            $table->text('description')->nullable();
            $table->json('amenities')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 16); // UnitStatus
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
