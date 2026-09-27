<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads coming through the marketing site's "Book a demo" flow.
 *
 * Central table, not tenant-scoped: these are prospects, no tenant
 * exists yet. Sales works from this queue directly. When a demo
 * converts we manually seed the tenant + operator via the admin
 * console and mark the row `status = converted`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 180);
            $table->string('phone', 32);
            $table->string('property_name', 160);
            $table->string('city', 80)->nullable();
            $table->string('property_size', 24)->nullable(); // '1-5' | '6-20' | '21-50' | '50+'
            $table->string('preferred_window', 24)->nullable(); // 'morning' | 'afternoon' | 'evening' | 'anytime'
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('new'); // new | contacted | demo_booked | converted | passed
            $table->string('source', 32)->default('landing'); // landing | pricing | closing | referral
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 400)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
