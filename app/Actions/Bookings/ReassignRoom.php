<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Multitenancy\TenantScope;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reassigns a booking to a different room in the same property — used
 * at check-in when the originally-picked room isn't available (overstay,
 * maintenance, VIP swap) or when the guest prefers a different unit.
 *
 * Cross-category is allowed: front-desk operators think in "which room
 * is free right now", not "same tier only". If the new room is in a
 * different category (different base_rate), booking totals are
 * recomputed so the ledger reflects reality; the caller handles the
 * financial reconciliation (collect overage / refund / issue coupon).
 *
 * Guards:
 *   - Booking must be in a state where the room assignment still
 *     matters (Pending, Confirmed, OnHold, CheckedIn). Reassigning a
 *     Cancelled / CheckedOut / NoShow booking is a no-op.
 *   - Target room must be Active.
 *   - Target room must be free for the booking's existing date range.
 *
 * Atomic swap inside a single DB transaction:
 *   1. Delete the booking's old AvailabilityBlock rows (release nights
 *      back to the old room's inventory).
 *   2. Reserve the same night-range on the new room via
 *      AvailabilityService — throws RangeNotAvailableException if a
 *      concurrent booking sneaked in, rolling everything back.
 *   3. Update booking.room_id + (if cross-rate) total_amount /
 *      room_total / balance_due.
 *
 * Atomicity ensures no scenario exists where the booking is "between
 * rooms" with neither set of availability blocks present.
 */
class ReassignRoom
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @throws RuntimeException for state / status violations
     * @throws RangeNotAvailableException when the target room is no longer free
     */
    public function handle(Booking $booking, Room $newRoom, Operator $operator): ReassignmentResult
    {
        $legal = [
            BookingStatus::Pending,
            BookingStatus::Confirmed,
            BookingStatus::OnHold,
            BookingStatus::CheckedIn,
        ];
        if (! in_array($booking->booking_status, $legal, true)) {
            throw new RuntimeException(
                "Cannot reassign a {$booking->booking_status->value} booking."
            );
        }

        if ($newRoom->id === $booking->room_id) {
            throw new RuntimeException('Booking is already assigned to that room.');
        }

        $oldRoom = $booking->room;
        if ($oldRoom === null) {
            throw new RuntimeException('Booking has no current room to reassign from.');
        }

        if ($newRoom->status !== RoomStatus::Active) {
            throw new RuntimeException('Target room is not active.');
        }

        $checkin = $booking->checkin_date->copy();
        $checkout = $booking->checkout_date->copy();

        // Pro-rata rate reconciliation. Nights the guest already
        // physically spent in the OLD room stay at the old rate — you
        // don't retroactively re-price a night that's already happened.
        // Only the REMAINING nights swap to the new rate, so the delta
        // = (newRate − oldRate) × remainingNights.
        //
        // Base rate (not RoomPrice overrides / packages) is what a
        // front-desk conversation references ("this suite is ₦10k
        // more per night"), and the reconciliation UI lets the
        // operator override the collected amount anyway.
        //
        // For not-yet-CheckedIn bookings, "remaining" is the full
        // stay — nothing has been spent yet.
        $oldRate = (int) $oldRoom->base_rate;
        $newRate = (int) $newRoom->base_rate;
        $nights = (int) $booking->nights;

        $today = Carbon::today();
        $isCheckedIn = $booking->booking_status === BookingStatus::CheckedIn;
        if ($isCheckedIn) {
            $nightsStayed = max(0, (int) $checkin->copy()->startOfDay()->diffInDays($today, absolute: false));
            $nightsStayed = min($nights, $nightsStayed);
        } else {
            $nightsStayed = 0;
        }
        $remainingNights = max(0, $nights - $nightsStayed);
        $rateDelta = ($newRate - $oldRate) * $remainingNights;
        $oldTotal = (int) $booking->total_amount;

        // CheckedIn guests: only the REMAINING nights move to the
        // new room. Past nights stay on the old room's ledger since
        // that's where the guest actually slept - moving them to
        // the new room would be an audit fiction AND would block
        // the reassign whenever another guest was in the new room
        // earlier in the week. Pre-arrival bookings keep the whole-
        // range swap since no nights have been spent yet.
        $today = Carbon::today()->startOfDay();
        $effectiveCheckin = $isCheckedIn
            ? ($today->greaterThan($checkin->copy()->startOfDay()) ? $today : $checkin->copy())
            : $checkin;

        // Pre-check availability over the SAME range we're actually
        // going to reserve. Using the full original range here (as an
        // earlier revision did) was over-conservative for CheckedIn
        // moves: a target room with only past-night blocks from a
        // previously-moved guest would falsely fail the pre-check
        // even though the actual reserve — which only touches nights
        // from today onwards — would succeed. The unique constraint
        // on availability_blocks is still the authoritative safety
        // net for races that slip past this check.
        if (! $this->availability->isAvailable($newRoom, $effectiveCheckin, $checkout)) {
            throw new RangeNotAvailableException(
                'Target room is no longer available for these dates.'
            );
        }

        return DB::transaction(function () use ($booking, $newRoom, $oldRoom, $operator, $effectiveCheckin, $checkout, $isCheckedIn, $rateDelta, $oldRate, $newRate, $oldTotal, $nights, $nightsStayed, $remainingNights): ReassignmentResult {
            // Release the old room's nights we're actually moving.
            // For CheckedIn we ONLY delete blocks from today onwards,
            // so the past-night ledger on the old room stays truthful.
            $oldRoomBlockQuery = AvailabilityBlock::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('booking_id', $booking->id)
                ->where('room_id', $oldRoom->id)
                ->where('type', AvailabilityBlockType::Booked->value);
            if ($isCheckedIn) {
                $oldRoomBlockQuery->whereDate('date', '>=', $effectiveCheckin->toDateString());
            }
            $oldRoomBlockQuery->delete();

            // Reserve new room's nights. Raises RangeNotAvailableException
            // on the unique-constraint race; the surrounding transaction
            // rolls back so both rooms' blocks stay coherent.
            $this->availability->reserveRangeForBooking(
                $newRoom, $effectiveCheckin, $checkout, $booking->id,
            );

            $updates = ['room_id' => $newRoom->id];
            // Cross-category reassignment: track the new category on
            // the booking too, so the header / arrivals table / stats
            // page all reflect what the guest is actually in - and so
            // isUnassigned() reports correctly if a follow-up flow
            // wants to drop back to category-only.
            if ((int) ($newRoom->room_category_id ?? 0) !== (int) ($booking->room_category_id ?? 0)) {
                $updates['room_category_id'] = $newRoom->room_category_id;
            }
            if ($rateDelta !== 0) {
                $updates['total_amount'] = max(0, $oldTotal + $rateDelta);
                $updates['room_total'] = max(0, (int) $booking->room_total + $rateDelta);
                // balance_due floors at 0. If the new room is cheaper
                // than what the guest already paid, the excess becomes
                // a refund or coupon (handled by the caller); it should
                // NOT re-open a debt they've already settled.
                $updates['balance_due'] = max(0, (int) $booking->balance_due + $rateDelta);
            }
            $booking->forceFill($updates)->save();

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('room_reassigned')
                ->withProperties([
                    'booking_ref' => $booking->booking_ref,
                    'old_room' => $oldRoom->room_number ?? (string) $oldRoom->id,
                    'new_room' => $newRoom->room_number ?? (string) $newRoom->id,
                    'old_room_id' => $oldRoom->id,
                    'new_room_id' => $newRoom->id,
                    'old_nightly_rate' => $oldRate,
                    'new_nightly_rate' => $newRate,
                    'rate_delta' => $rateDelta,
                    'nights' => $nights,
                    'nights_stayed_at_old_rate' => $nightsStayed,
                    'remaining_nights_at_new_rate' => $remainingNights,
                ])
                ->log(sprintf(
                    'Operator %s reassigned %s from room %s to room %s (rate delta: %s).',
                    $operator->name,
                    $booking->booking_ref,
                    $oldRoom->room_number ?? (string) $oldRoom->id,
                    $newRoom->room_number ?? (string) $newRoom->id,
                    $rateDelta === 0 ? 'none' : ($rateDelta > 0 ? '+' : '−').'₦'.number_format(abs($rateDelta)),
                ));

            return new ReassignmentResult(
                booking: $booking->refresh(),
                rateDelta: $rateDelta,
                oldNightlyRate: $oldRate,
                newNightlyRate: $newRate,
                nights: $nights,
                remainingNights: $remainingNights,
                fullNightsDelta: ($newRate - $oldRate) * $nights,
            );
        });
    }
}
