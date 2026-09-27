<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Services\Customers\CustomerLinker;

/**
 * Mirrors NotifyOperatorsOfBookingActivity but for the CRM timeline:
 * every confirmed / cancelled booking gets a "booking.confirmed" or
 * "booking.cancelled" system event on the customer record + a fresh
 * aggregate recompute (lifetime_value, total_bookings, lifecycle).
 *
 * Idempotent — re-delivered events do not double-write timeline rows.
 */
class UpdateCustomerOnBookingActivity
{
    public function __construct(private readonly CustomerLinker $linker) {}

    public function handleConfirmed(BookingConfirmed $event): void
    {
        $b = $event->booking;

        $this->linker->recordBookingEvent(
            booking: $b,
            event: 'booking.confirmed',
            subject: 'Booking '.$b->booking_ref.' confirmed',
            body: 'Payment cleared. ₦'.number_format((int) $b->total_amount).' settled.',
        );
    }

    public function handleCancelled(BookingCancelled $event): void
    {
        $b = $event->booking;
        $depositForfeit = (int) ($event->depositForfeit ?? 0);

        $body = 'Booking cancelled.';
        if ($depositForfeit > 0) {
            $body .= ' Deposit ₦'.number_format($depositForfeit).' forfeited.';
        }

        $this->linker->recordBookingEvent(
            booking: $b,
            event: 'booking.cancelled',
            subject: 'Booking '.$b->booking_ref.' cancelled',
            body: $body,
        );
    }
}
