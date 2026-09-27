<?php

declare(strict_types=1);

namespace App\Events;

use App\Actions\Bookings\CancelBooking;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after {@see CancelBooking} commits.
 * `$depositForfeit` carries the policy outcome so listeners can phrase
 * the email body / in-dashboard notification accurately without redoing
 * the calc.
 */
class BookingCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly bool $depositForfeit = false,
    ) {}
}
