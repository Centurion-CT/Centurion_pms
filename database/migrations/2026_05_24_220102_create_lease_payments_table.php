<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->date('due_at');
            $table->string('label', 64); // e.g. "Year 1 rent", "Deposit", "Service charge"
            $table->unsignedInteger('amount_naira');
            $table->string('status', 16); // LeasePaymentStatus
            $table->dateTime('paid_at')->nullable();
            $table->string('paystack_reference', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'lease_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_payments');
    }
};
