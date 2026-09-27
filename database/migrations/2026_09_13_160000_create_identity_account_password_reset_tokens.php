<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reset-token store for the central-domain identity broker
 * (docs/unified-login.md). Standard Laravel shape — email keyed,
 * hashed token, created_at. Rows expire per config/auth.php.
 *
 * Kept in its own table (not sharing operators') so a compromised
 * operator reset token can't be replayed against an identity, and
 * so the "identities" broker can throttle independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_account_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_account_password_reset_tokens');
    }
};
