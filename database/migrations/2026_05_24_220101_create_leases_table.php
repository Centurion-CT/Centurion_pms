<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('reference', 16)->unique();
            $table->string('status', 16); // LeaseStatus
            $table->date('started_at');
            $table->date('expires_at');
            $table->unsignedSmallInteger('term_months');
            $table->unsignedInteger('annual_rent_naira');
            $table->unsignedInteger('monthly_rent_naira')->nullable();
            $table->unsignedInteger('deposit_naira')->default(0);
            $table->unsignedInteger('service_charge_naira')->default(0);
            // Days the operator wants to alert before each rent due date.
            // Drives the renewal nudge email.
            $table->unsignedSmallInteger('renewal_reminder_days')->default(60);
            // BunnyCDN path to the signed lease PDF.
            $table->string('document_path')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'unit_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
