<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a given CorporatePayment landed on which invoice. A
 * ₦100k payment on two ₦60k invoices splits 60/40 across two
 * allocation rows. Keeps the audit trail truthful — every naira the
 * corp sent us can be traced back to the invoice it settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_payment_id')
                ->constrained('corporate_payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')
                ->constrained('invoices')->cascadeOnDelete();
            $table->unsignedInteger('amount_naira');
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_payment_allocations');
    }
};
