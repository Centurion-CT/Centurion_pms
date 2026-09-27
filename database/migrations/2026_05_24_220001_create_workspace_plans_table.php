<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Nullable so a plan can apply across many workspaces of the
            // same type (e.g. "Hot Desk Monthly" works on any hot desk).
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('billing_interval', 16); // BillingInterval
            $table->unsignedInteger('price_naira');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            // Soft cap on concurrent active members on this plan.
            // Null = unlimited.
            $table->unsignedSmallInteger('member_cap')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_plans');
    }
};
