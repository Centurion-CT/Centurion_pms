<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 4-digit PIN a guest sets on their first QR-portal scan at a
 * property — bound to (customer_id, property_id) so:
 *   - a guest returning to the same property re-uses the PIN across
 *     multiple stays / rooms (no re-setup);
 *   - the same customer visiting a DIFFERENT branch under the same
 *     org sets a fresh PIN there (branches are independent security
 *     domains, matches guest expectation);
 *   - the PIN is scoped away from the operator dashboard entirely
 *     — operators can't read it (hashed) and can't set it FOR the
 *     guest.
 *
 * The hash uses Laravel's Hash facade (bcrypt) — 4 digits is only
 * 10k combos but the PIN check is throttled + the portal is scoped
 * to a single room (attacker also needs the QR slug + a matching
 * phone number on that room's active booking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_room_pins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('property_id');
            $table->string('pin_hash', 100);
            $table->timestamps();

            $table->unique(['customer_id', 'property_id'], 'guest_room_pins_cust_prop_unique');
            $table->index('customer_id');
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_room_pins');
    }
};
