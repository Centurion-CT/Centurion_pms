<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Negotiated rates per corporate. Resolved at booking time most-
 * specific-first: Unit → Workspace → RoomCategory → Property → Global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 16); // CorporateRateScope
            // Nullable for global rates; otherwise references the
            // scoped table (Property/RoomCategory/Workspace/Unit).
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->string('rate_type', 16); // CorporateRateType
            // Integer payload — percent (0..100), fixed ₦ off, or flat ₦ price.
            // Validation lives in the form layer.
            $table->integer('rate_value');

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['corporate_id', 'scope', 'scope_id']);
            $table->index(['corporate_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_rates');
    }
};
