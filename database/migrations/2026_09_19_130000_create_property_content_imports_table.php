<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super-admin-driven concierge content import for property setup.
 *
 * Admin pastes any subset of Google Business / Booking.com / Airbnb URLs;
 * a queued job fetches structured content, and this row holds the state:
 * source URLs + suggested JSON + approved JSON + who did what + when the
 * operator was notified. Approving writes accepted fields onto the
 * property record; suggestions are wiped after resolution but the row
 * stays for audit.
 *
 * Not tenant-scoped: super admins operate above the tenant boundary,
 * so we scope by tenant_id at row level but the model is imported by
 * Admin routes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_content_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('google_url', 512)->nullable();
            $table->string('booking_url', 512)->nullable();
            $table->string('airbnb_url', 512)->nullable();
            $table->string('status', 32)->default('pending');
            $table->json('suggested_content')->nullable();
            $table->json('approved_content')->nullable();
            $table->text('error_message')->nullable();
            // No FK: solo has no `admins` table (dropped in phase 1a).
            $table->foreignId('run_by_admin_id')->nullable();
            $table->foreignId('approved_by_admin_id')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('notified_operator_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_content_imports');
    }
};
