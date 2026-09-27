<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use Illuminate\Support\Facades\DB;

/**
 * Front-desk assigns a real room to a category-level reservation
 * at check-in time. Two things happen in one transaction:
 *
 *   1. The booking's room_id flips from NULL to the picked room.
 *   2. Every category-level AvailabilityBlock on that booking is
 *      re-pointed at the specific room (via AvailabilityService's
 *      reassignCategoryBookingToRoom, which also guards against
 *      the picked room being double-booked on any night in the
 *      range).
 *
 * Kept surgical - the booking's guest / dates / totals stay
 * untouched; only the room assignment changes.
 */
class AssignRoomToBooking
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @throws BookingValidationException      unassignable state (room already set,
     *                                         wrong category, etc.)
     * @throws RangeNotAvailableException     target room clashes on any night
     */
    public function handle(Booking $booking, Room $room, ?Operator $by = null): Booking
    {
        if ($booking->room_id !== null) {
            throw new BookingValidationException('This booking already has a room assigned.');
        }
        if ($booking->room_category_id === null) {
            throw new BookingValidationException('This booking has no category to assign against.');
        }
        if ((int) $room->room_category_id !== (int) $booking->room_category_id) {
            throw new BookingValidationException(
                'The picked room is in a different category than the reservation.'
            );
        }
        if ((int) $room->tenant_id !== (int) $booking->tenant_id) {
            throw new BookingValidationException(
                'The picked room belongs to a different tenant.'
            );
        }

        DB::transaction(function () use ($booking, $room): void {
            $this->availability->reassignCategoryBookingToRoom($booking->id, $room);
            $booking->forceFill(['room_id' => $room->id])->save();
        });

        activity('booking')
            ->causedBy($by)
            ->performedOn($booking)
            ->event('room_assigned')
            ->withProperties(['room_id' => $room->id, 'room_number' => $room->room_number])
            ->log(sprintf(
                'Room %s assigned to category-level booking %s.',
                $room->room_number,
                $booking->booking_ref,
            ));

        return $booking->refresh();
    }
}
