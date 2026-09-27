<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-Keverna guest PIN — keyed on a hash of the guest's phone
 * (last-9 digits normalised) so the same person visiting ANY
 * Keverna property enters the same PIN.
 *
 * The prior `guest_room_pins` table (per customer_id + property_id)
 * stays in place as a fallback read path for existing guests until
 * they next re-verify, at which point the flow writes to this new
 * table too. Once no rows are read from the old table for a while,
 * a follow-up migration can drop it entirely.
 *
 * Why hash the phone rather than store it plain:
 *   - A leak of THIS table (say a mis-configured backup) shouldn't
 *     hand attackers a directory of every Keverna guest's phone
 *     number cross-referenced with a hashed PIN.
 *   - Hashing means we lose the ability to "find a PIN for phone
 *     080X" without also having the phone in hand — that's the
 *     point. The auth flow always has the phone in hand (the guest
 *     just typed it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_global_pins', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_hash', 64)->unique('guest_global_pins_phone_hash_unique');
            $table->string('pin_hash', 100);
            // Rate-limit signal — bumped on wrong PIN, cleared on
            // success. Used by the portal to lock the flow after N
            // bad attempts before the operator has to intervene.
            $table->unsignedTinyInteger('recent_failures')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_global_pins');
    }
};
