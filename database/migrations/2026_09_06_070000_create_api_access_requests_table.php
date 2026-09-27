<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applicants for the Keverna public API early-access programme.
 * Central table (no tenant scope) - most of these are external
 * developers with no existing tenant, and even when they have one
 * we treat the interest signal as a platform-level record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 180);
            $table->string('company', 160)->nullable();
            $table->string('github_url', 240)->nullable();
            $table->string('use_case', 400)->nullable();
            $table->string('integrations', 300)->nullable();
            $table->string('status', 16)->default('new'); // new | contacted | approved | rejected
            $table->text('admin_notes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 400)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_access_requests');
    }
};
