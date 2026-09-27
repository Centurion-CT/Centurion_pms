<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Models\Booking;
use App\Models\BookingChangeRequest;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Records a guest's request to change dates (PRD §6.4.4 / §13). This does
 * NOT mutate the booking or its AvailabilityBlocks — it just creates a
 * pending request the operator approves/rejects from the dashboard.
 *
 * Validation in v1 is intentionally light: we only check the requested
 * range is sane (checkout > checkin, both in the future). The operator
 * decides if their inventory can absorb the move; that's why we don't
 * pre-flight availability here.
 *
 * Idempotency: a single PENDING request per booking. A second submission
 * while one is open is rejected — the guest must wait for a decision or
 * cancel the first via the operator.
 */
class RequestDateChange
{
    public function handle(
        Booking $booking,
        Carbon $requestedCheckin,
        Carbon $requestedCheckout,
        ?string $guestNote = null,
    ): BookingChangeRequest {
        if (! $booking->booking_status->holdsInventory()) {
            throw new RuntimeException(
                "Booking {$booking->booking_ref} is {$booking->booking_status->value}; date changes are not accepted.",
            );
        }

        $checkin = $requestedCheckin->copy()->startOfDay();
        $checkout = $requestedCheckout->copy()->startOfDay();

        if (! $checkout->greaterThan($checkin)) {
            throw new InvalidArgumentException('Requested checkout must be after requested checkin.');
        }

        if ($checkin->lessThanOrEqualTo(Carbon::today())) {
            throw new InvalidArgumentException('Requested checkin must be in the future.');
        }

        $hasPending = BookingChangeRequest::query()
            ->where('booking_id', $booking->id)
            ->where('status', BookingChangeRequest::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            throw new RuntimeException(
                "Booking {$booking->booking_ref} already has a pending date change. Wait for the operator to respond.",
            );
        }

        return BookingChangeRequest::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'requested_checkin' => $checkin->format('Y-m-d H:i:s'),
            'requested_checkout' => $checkout->format('Y-m-d H:i:s'),
            'guest_note' => $guestNote !== null ? trim($guestNote) : null,
            'status' => BookingChangeRequest::STATUS_PENDING,
        ]);
    }
}
