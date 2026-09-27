<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subscription is one customer's active grip on one plan. started_at
 * + expires_at bound the current period; on renew we shift both
 * forward by the plan's validity_days. status is soft-derived from
 * expires_at + cancelled_at but we persist it so index-only queries
 * (Active Members KPI, expiring-soon list) stay fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Denormalised business_id so we can index list queries
            // by (tenant_id, business_id) without joining plans.
            $table->foreignId('business_id')
                ->constrained('membership_businesses')->cascadeOnDelete();
            $table->foreignId('plan_id')
                ->constrained('membership_plans')->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->constrained('customers')->cascadeOnDelete();
            $table->string('member_ref')->unique();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active');    // active | expired | cancelled
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'business_id', 'status']);
            $table->index(['tenant_id', 'expires_at']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_subscriptions');
    }
};
