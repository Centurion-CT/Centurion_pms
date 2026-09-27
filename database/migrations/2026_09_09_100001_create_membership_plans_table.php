<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan is a purchasable subscription tier under a business.
 * "Gym Monthly ₦15,000, valid 30 days" is one plan; "Gym Quarterly
 * ₦40,000, valid 90 days" is another. price is stored in naira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('business_id')
                ->constrained('membership_businesses')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('validity_days');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'business_id']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
