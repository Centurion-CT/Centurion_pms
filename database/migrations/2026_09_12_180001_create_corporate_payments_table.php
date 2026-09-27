<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money the corporate has sent us against their outstanding invoices.
 * The RecordCorporatePayment action walks unpaid corp invoices oldest-
 * first and creates one CorporatePaymentAllocation per invoice it
 * touches — total ledger stays honest even when a single payment
 * splits across multiple invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('corporate_id')->constrained('corporates')->cascadeOnDelete();
            $table->foreignId('recorded_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->unsignedInteger('amount_naira');
            $table->string('method', 24);     // cash | transfer | card | pos | other
            $table->timestamp('paid_at');
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->string('receipt_path', 240)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'corporate_id']);
            $table->index(['tenant_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_payments');
    }
};
