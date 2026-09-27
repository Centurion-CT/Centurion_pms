<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual staff member belonging to a corporate. The email captured
 * here is the lookup key for resolution at booking time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_id')->constrained()->cascadeOnDelete();
            // Optional link to the platform's Customer record — set once
            // the member first books, so per-tenant CRM stays useful.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 160);
            $table->string('email', 180);
            $table->string('phone', 32)->nullable();
            $table->string('employee_id', 64)->nullable();
            $table->string('role', 16)->default('member'); // CorporateMemberRole
            $table->string('status', 16)->default('invited'); // CorporateMemberStatus

            // Spend controls (₦, daily / monthly). Null = unlimited.
            $table->unsignedInteger('daily_cap_naira')->nullable();
            $table->unsignedInteger('monthly_cap_naira')->nullable();

            $table->dateTime('invited_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('offboarded_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Email is unique per corporate (the same person could in
            // theory belong to two corporates — rare but allowed).
            $table->unique(['corporate_id', 'email']);
            $table->index(['email']);
            $table->index(['corporate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_members');
    }
};
