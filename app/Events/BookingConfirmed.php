<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once after a Booking transitions to Confirmed (full payment) or
 * OnHold (deposit only) — see ConfirmBookingPayment. Listeners send the
 * guest + operator emails.
 */
class BookingConfirmed
{
    use Dispatchable;

    public function __construct(public Booking $booking) {}
}
