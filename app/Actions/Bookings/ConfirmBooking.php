<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Services\Corporates\CorporateBookingService;
use RuntimeException;

/**
 * Operator-side action: manually flip a booking to Confirmed (used for
 * walk-ins / phone bookings whose payment was settled off-platform).
 *
 * Forbidden on Cancelled / CheckedOut — those are terminal in v1.
 */
class ConfirmBooking
{
    public function __construct(
        private readonly CorporateBookingService $corporates,
        private readonly RecordManualPayment $recordPayment,
    ) {}

    public function handle(Booking $booking, Operator $operator): Booking
    {
        if (in_array($booking->booking_status, [BookingStatus::Cancelled, BookingStatus::CheckedOut], true)) {
            throw new RuntimeException(
                "Booking {$booking->booking_ref} is {$booking->booking_status->value}; cannot mark it confirmed.",
            );
        }

        $booking->forceFill([
            'booking_status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Succeeded,
        ])->save();

        // Record the cash on the Payment ledger so Finance's "Collected"
        // + Reports' revenue actually see it. Idempotent — a booking
        // already covered by Succeeded payments is a no-op.
        $this->recordPayment->handle($booking->refresh(), $operator);

        // Capture the corporate wallet hold (no-op for non-corporate
        // bookings or when already captured).
        $this->corporates->captureFor($booking->refresh());

        activity('booking')
            ->causedBy($operator)
            ->performedOn($booking)
            ->event('confirmed')
            ->log("Operator {$operator->name} marked booking {$booking->booking_ref} as Confirmed.");

        return $booking->refresh();
    }
}
