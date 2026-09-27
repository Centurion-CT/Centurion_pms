<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporates — companies onboarded by Keverna whose staff can use any
 * property on the platform. Central table; no tenant_id (cross-tenant
 * by design).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporates', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 160);
            $table->string('status', 16); // CorporateStatus

            // Primary contact.
            $table->string('primary_email', 180);
            $table->string('primary_phone', 32)->nullable();
            $table->string('billing_email', 180)->nullable();

            // Address + jurisdiction.
            $table->string('address', 240)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 2)->default('NG'); // ISO 2-letter

            // Trust / compliance.
            $table->string('registration_number', 64)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->string('kyc_status', 16)->default('pending'); // CorporateKycStatus
            $table->text('kyc_notes')->nullable();
            $table->dateTime('contract_signed_at')->nullable();

            // Pre-approval threshold — bookings above this notify the
            // corporate admin before confirming. Nullable = no
            // pre-approval required.
            $table->unsignedInteger('approval_threshold_naira')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('kyc_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporates');
    }
};
