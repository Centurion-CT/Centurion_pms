<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per subscription period (PRD §9). Trial registrations get a zero-
 * amount trial row; renewals/upgrades append a new row. Tenant.status +
 * Tenant.subscription_expires_at is the live state derived from the most
 * recent active row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 32);                 // 'trial' | 'annual_300k'
            $table->unsignedInteger('amount');          // NGN
            $table->string('status', 16)->index();      // 'trial' | 'active' | 'expired' | 'failed'
            $table->string('paystack_reference')->nullable()->unique();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
