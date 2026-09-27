<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant catalog of bookable add-on packages (breakfast, airport
 * pickup, late checkout, spa credit, etc.). Each package is either
 * complimentary (price ignored, offered free) or paid (price charged
 * to the guest). Selection happens during initial booking and/or via
 * the guest portal after booking, gated by the two `available_*` flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();

            $table->string('name', 120);
            $table->text('description')->nullable();

            // Complimentary packages ignore `price` and are offered free.
            // Paid packages charge `price` × quantity to the guest.
            $table->boolean('is_complimentary')->default(false);
            $table->unsignedBigInteger('price')->default(0);

            // Per-booking quantity cap. NULL = pick at most once.
            $table->unsignedSmallInteger('max_per_booking')->default(1);

            // Surface flags — operator can disable a package from one or
            // both flows without deleting it.
            $table->boolean('available_during_booking')->default(true);
            $table->boolean('available_after_booking')->default(true);

            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
