<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One wallet per corporate. Tracks total balance + how much of it is
 * currently held by in-flight bookings.
 *
 *   available = balance - held
 *
 * Multi-currency lands in v2; v1 is ₦ only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance_naira')->default(0);
            $table->unsignedBigInteger('held_naira')->default(0);
            // Email the corporate admin when available balance drops below this.
            $table->unsignedInteger('low_balance_threshold_naira')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_wallets');
    }
};
