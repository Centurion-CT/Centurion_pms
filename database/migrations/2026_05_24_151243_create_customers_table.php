<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers — the CRM identity that survives across bookings.
 *
 * Identity resolution order: email (primary), then phone (fallback).
 * `lifetime_value` and `total_bookings` are denormalised projections of
 * the related bookings; recalculated by CustomerLinker on each new
 * booking + by the backfill command for historical rows.
 *
 * Tags are stored as a JSON array of strings so operators can build
 * arbitrary segments ("VIP", "Diaspora", "Corporate") without a
 * separate pivot. Lookups happen via JSON path queries (MySQL 5.7+ /
 * SQLite 3.38+ both support this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Identity
            $table->string('name', 160);
            $table->string('email', 200)->nullable();
            $table->string('phone', 40)->nullable();

            // Profile (operator-editable; not surfaced to the guest)
            $table->string('address', 240)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country', 80)->nullable();

            // Long-form notes the operator keeps about the guest
            // ("prefers north-facing rooms", "allergic to peanuts",
            // "celebrated 25th anniversary with us in 2024").
            $table->text('notes')->nullable();

            // Tags — JSON array of free-text labels. Indexed individually
            // via virtual columns at the query layer if needed.
            $table->json('tags')->nullable();

            // Lifecycle bucket (enum string) — drives segment filters.
            $table->string('lifecycle', 24)->default('prospect');

            // Flagged VIPs jump to the top of every list + get badges
            // on the booking screen for staff.
            $table->boolean('is_vip')->default(false);

            // Marketing consent — required for any future broadcast tooling.
            $table->boolean('marketing_opt_in')->default(false);

            // Aggregates (kept fresh by CustomerLinker)
            $table->unsignedInteger('total_bookings')->default(0);
            $table->unsignedBigInteger('lifetime_value')->default(0); // NGN
            $table->timestamp('first_booking_at')->nullable();
            $table->timestamp('last_booking_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Email is the strongest identity signal; unique per tenant
            // (different tenants can independently have the same guest).
            // Nullable because manual-entry CRM records may have phone only.
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'lifecycle']);
            $table->index(['tenant_id', 'last_booking_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
