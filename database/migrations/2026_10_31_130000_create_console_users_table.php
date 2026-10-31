<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * People who may use the platform owner's console (docs/MULTITENANCY.md,
 * step 7). Not tied to any business. The first owner is created on the
 * console's /setup page; owners invite the rest. `password` is null
 * until an invited user sets one from their invite link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password')->nullable();
            $table->string('role', 16); // owner | admin
            $table->foreignId('invited_by_id')->nullable()->constrained('console_users')->nullOnDelete();
            $table->string('invite_token_hash', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamp('last_signed_in_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_users');
    }
};
