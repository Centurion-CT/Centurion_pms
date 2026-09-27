<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Models\Booking;

/**
 * What {@see CancelBooking} returns. `depositForfeit` answers the question
 * "did we keep the deposit per the cancellation policy?" — the UI uses it
 * to phrase the confirmation message.
 */
final readonly class CancelBookingResult
{
    public function __construct(
        public Booking $booking,
        public bool $depositForfeit,
        public int $daysUntilCheckin,
    ) {}
}
