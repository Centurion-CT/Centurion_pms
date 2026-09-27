<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_plan_id')->constrained('workspace_plans')->restrictOnDelete();
            // Optional — only set for dedicated-desk / private-office
            // assignments. Hot-desk memberships leave this null.
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16); // MembershipStatus
            $table->date('started_at');
            $table->date('next_renewal_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->unsignedInteger('amount_naira');
            $table->string('billing_interval', 16); // BillingInterval (mirrored for history)
            // Paystack subscription code (when renewals are on autopilot).
            // Null for manual-collection arrangements.
            $table->string('paystack_subscription_code', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'next_renewal_at']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
