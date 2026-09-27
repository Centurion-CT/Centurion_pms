<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Multitenancy\TenantScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the property's late-/early-checkout policy onto each booking
 * at check-in. Guests already in-house at the moment the property
 * changes its policy keep the policy they arrived under; new arrivals
 * from that point pick up the fresh policy. Booking rows without a
 * snapshot (pre-migration bookings) fall through to the live property
 * config so nothing regresses.
 *
 * The migration also backfills currently-CheckedIn rows with the live
 * property snapshot so the first policy change after deploy doesn't
 * silently rewrite the rules for a guest already in the room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->json('checkout_policy_snapshot')->nullable()->after('review_prompt_sent_at');
        });

        // Backfill currently in-house guests. Central-scope traversal so
        // every tenant's checked-in rows get sealed with today's policy.
        Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['room.property'])
            ->where('booking_status', 'checked_in')
            ->whereNull('checkout_policy_snapshot')
            ->cursor()
            ->each(function (Booking $b): void {
                $property = $b->room?->property;
                if ($property === null) return;

                $b->forceFill([
                    'checkout_policy_snapshot' => array_merge(
                        $property->checkoutPolicy(),
                        [
                            'check_out_to' => (string) ($property->houseRules()['check_out_to'] ?? ''),
                            'snapshot_at' => Carbon::now()->toIso8601String(),
                            'snapshot_source' => 'migration_backfill',
                        ],
                    ),
                ])->save();
            });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('checkout_policy_snapshot');
        });
    }
};
