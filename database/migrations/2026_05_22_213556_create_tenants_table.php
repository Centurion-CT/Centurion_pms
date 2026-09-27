<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRD §9 — central tenants table. No `tenant_id` column (tenants ARE the tenant).
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email');
            $table->string('phone')->nullable();

            // Future FK to a plans table. String is enough to satisfy PRD §9.
            $table->string('plan_id')->nullable();

            // PRD §4.2 state machine: trial → active → grace → locked → archived.
            $table->string('status', 16)->default('trial')->index();

            // PRD §10: AES-256 at rest. Laravel's `encrypted` cast on the model handles this.
            $table->text('paystack_secret_key')->nullable();

            // PRD §6.6.2: bring-your-own custom domain. NULL until the operator adds one.
            $table->string('custom_domain')->nullable()->unique();
            $table->timestamp('domain_verified_at')->nullable();

            // PRD §4.2: subscription lifecycle drives this; renewal reminders fire off it.
            $table->timestamp('subscription_expires_at')->nullable()->index();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
