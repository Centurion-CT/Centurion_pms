<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System-wide auth tables (PRD §2). Operators and admins each get their own
 * password-reset bucket so a single global "users" table never exists. The
 * file name is preserved so Laravel's expected boot order is undisturbed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('admin_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            // The authenticated user can be either an operator or an admin.
            // Left un-FK'd because they live in separate tables.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('admin_password_reset_tokens');
        Schema::dropIfExists('operator_password_reset_tokens');
    }
};
