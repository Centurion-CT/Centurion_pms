<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\RoomInspectionStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\RoomInspection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Operator-side check-in. Guards:
 *
 *  - Only Confirmed or OnHold bookings can be checked in (Pending = unpaid;
 *    Cancelled / CheckedOut / NoShow = terminal).
 *  - Today must be >= checkin_date - we don't allow operators to "check in"
 *    a future booking (the calendar would then mark a room occupied
 *    early, breaking availability).
 *  - The room must be physically empty. If a different booking is still
 *    CheckedIn (an overstayed guest who hasn't been checked out), the
 *    new check-in is refused - the operator must run check-out on the
 *    old guest first. {@see MarkCheckedOut} will auto-charge any
 *    overstay fees when that happens.
 */
class MarkCheckedIn
{
    /**
     * Set by handle() when the target room has an in-progress
     * housekeeping inspection (pending / cleaning-done / porter-
     * verified). The caller reads this after a successful check-in
     * and surfaces a warning toast so the front desk knows the
     * room still needs cleaning attention. A `Failed` inspection
     * (supervisor flagged the room as unfit) is a HARD block and
     * throws instead — this property stays null in that case.
     */
    public ?RoomInspection $pendingInspection = null;

    public function handle(Booking $booking, Operator $operator): Booking
    {
        $this->pendingInspection = null;

        if (! in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true)) {
            throw new RuntimeException(
                "Cannot check in a booking in state {$booking->booking_status->value}.",
            );
        }

        // Category-level reservation (room_id NULL) - front desk has
        // to pick a specific room via AssignRoomToBooking first, or
        // the occupancy guard below would trip on a NULL room_id +
        // the housekeeping / calendar downstream have no room to
        // update. Kept as a friendly BookingValidationException so
        // the checkin UI can surface an "Assign a room first" prompt
        // rather than a 500.
        if ($booking->room_id === null) {
            throw new BookingValidationException(
                'This booking has no room assigned. Pick a room from the category before checking in.'
            );
        }

        if (Carbon::today()->lt($booking->checkin_date->copy()->startOfDay())) {
            throw new InvalidArgumentException(
                'Cannot check in before the booking checkin date.',
            );
        }

        // Room occupancy guard - a CheckedIn booking on the same room
        // means a previous guest hasn't been checked out yet.
        $occupier = Booking::query()
            ->where('room_id', $booking->room_id)
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->where('id', '!=', $booking->id)
            ->first(['id', 'booking_ref', 'guest_name', 'checkout_date']);

        if ($occupier !== null) {
            throw new RuntimeException(sprintf(
                'Room is still occupied by %s (%s · booked through %s). Check that guest out first - any overstay fees will be added automatically.',
                $occupier->guest_name,
                $occupier->booking_ref,
                Carbon::parse((string) $occupier->checkout_date)->toFormattedDateString(),
            ));
        }

        // Inspection gate. Three paths, keyed off the LATEST inspection
        // for the room (not "latest non-passed" - filtering Passed out
        // first would let a stale Failed record from a prior stay
        // shadow a fresh Passed one and block a clean room):
        //
        //   Failed → supervisor / admin explicitly flagged the room as
        //     not fit for a guest. HARD block: front desk can't push
        //     through until the flag is cleared or a new inspection
        //     re-opens the cycle.
        //
        //   In-progress (pending / cleaning-done / porter-verified) →
        //     the standard housekeeping chain hasn't finished yet.
        //     Front desk can still check the guest in (real-world:
        //     they need to seat the guest even if housekeeping is
        //     mid-turnover), but the pending inspection is stashed on
        //     the action so the caller can raise a warning toast.
        //
        //   Passed (or no inspection ever) → clean, no gate.
        $room = $booking->room;
        if ($room !== null) {
            $latestInspection = RoomInspection::query()
                ->where('room_id', $room->id)
                ->latest('id')
                ->first();
            if ($latestInspection !== null
                && $latestInspection->status !== RoomInspectionStatus::Passed
            ) {
                if ($latestInspection->status === RoomInspectionStatus::Failed) {
                    throw new RuntimeException(
                        'This room was flagged as not fit for a guest. Reopen the inspection or clear the flag before checking someone in.'
                    );
                }
                $this->pendingInspection = $latestInspection;
            }
        }

        // Snapshot the property's current late-/early-checkout policy
        // onto the booking. Once frozen, later property-level changes
        // don't retroactively rewrite what this guest was told at
        // check-in - the calculator prefers the snapshot on all
        // downstream fee math. See Booking::effectiveLateCheckoutPolicy.
        $snapshot = $booking->checkout_policy_snapshot;
        $property = $booking->room?->property;
        if ($property !== null && (! is_array($snapshot) || $snapshot === [])) {
            $snapshot = Booking::buildCheckoutPolicySnapshot($property, source: 'checkin');
        }

        $booking->forceFill([
            'booking_status' => BookingStatus::CheckedIn,
            'checkout_policy_snapshot' => $snapshot,
        ])->save();

        activity('booking')
            ->causedBy($operator)
            ->performedOn($booking)
            ->event('checked_in')
            ->withProperties(['policy_snapshot_source' => is_array($snapshot) ? ($snapshot['snapshot_source'] ?? null) : null])
            ->log("Operator {$operator->name} checked in {$booking->guest_name} ({$booking->booking_ref}).");

        return $booking->refresh();
    }
}
