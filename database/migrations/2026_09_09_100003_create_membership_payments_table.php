<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment history per subscription. Own table (not the general
 * bookings Payment) so we can index by subscription_id without a
 * nullable-heavy polymorphic column and so revenue reports for
 * memberships stay isolated from room revenue.
 *
 * kind separates the receipt into initial (signup), renewal (period
 * rolled forward), extension (extra days without a new period), and
 * other. period_start / period_end record which coverage window this
 * money bought so the operator can point at "which cycle did they
 * pay for?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_id')
                ->constrained('membership_subscriptions')->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->unsignedInteger('amount');
            $table->string('method');       // cash | transfer | card | pos | other
            $table->string('status')->default('succeeded');
            $table->string('kind')->default('initial'); // initial | renewal | extension | other
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('collected_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['tenant_id', 'subscription_id']);
            $table->index(['tenant_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_payments');
    }
};
