<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Events\BookingConfirmed;
use App\Listeners\SendBookingConfirmationMail;
use App\Models\Booking;
use App\Models\Operator;
use Illuminate\Support\Facades\Event;

/**
 * Operator-triggered resend of the confirmation email. Re-fires
 * {@see BookingConfirmed}, which the existing listener
 * {@see SendBookingConfirmationMail} already handles —
 * including issuing a fresh portal token.
 *
 * Idempotency: each resend issues a NEW token (old ones are still valid
 * until they expire); the operator paying the email-cost is in control.
 */
class ResendBookingConfirmation
{
    public function handle(Booking $booking, Operator $operator): Booking
    {
        Event::dispatch(new BookingConfirmed($booking));

        activity('booking')
            ->causedBy($operator)
            ->performedOn($booking)
            ->event('resent_confirmation')
            ->log("Operator {$operator->name} resent the confirmation email for {$booking->booking_ref}.");

        return $booking;
    }
}
