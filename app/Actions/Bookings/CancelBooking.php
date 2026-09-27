<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Events\BookingCancelled;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Multitenancy\TenantScope;
use App\Services\Corporates\CorporateBookingService;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Cancels a booking via the guest portal (PRD §6.4.4) or operator dashboard.
 *
 * v1 cancellation policy (hardcoded; per-room override is a v2 roadmap item):
 *   - Cancel ≥ 7 days before checkin → no penalty (full or deposit returned
 *     out-of-band by the operator)
 *   - Cancel < 7 days before checkin → deposit forfeit (operator keeps it)
 *
 * The booking is marked Cancelled and the Booked AvailabilityBlock rows
 * are deleted (operator's Blocked rows on the same dates are preserved —
 * same surgical filter as ReleaseStalePendingBookings / AutoCancelExpiredHolds).
 *
 * NO Paystack refund is initiated automatically. Refund decisions stay with
 * the operator; they execute via the Paystack dashboard.
 */
class CancelBooking
{
    private const POLICY_GRACE_DAYS = 7;

    public function __construct(
        private readonly CorporateBookingService $corporates,
    ) {}

    /**
     * @param  bool  $refundRequested  Operator-only flag. The booking is
     *                                 marked cancelled regardless; setting this records a "refund
     *                                 requested" audit row so the operator can reconcile it on the
     *                                 Paystack dashboard. v1 does NOT call the Paystack refunds API
     *                                 automatically — that's a v2 ticket (PRD §15.4).
     */
    public function handle(
        Booking $booking,
        ?Operator $operator = null,
        bool $refundRequested = false,
    ): CancelBookingResult {
        if (! $booking->booking_status->holdsInventory()) {
            throw new RuntimeException(
                "Booking {$booking->booking_ref} is already {$booking->booking_status->value} and cannot be cancelled.",
            );
        }
        // Cancel is a pre-arrival action — a CheckedIn booking exits
        // via Check out, not Cancel. Room-nights + payments for a
        // guest already in-house are real and can't be un-created.
        if ($booking->booking_status === BookingStatus::CheckedIn) {
            throw new RuntimeException(
                "Booking {$booking->booking_ref} is already checked in — check the guest out instead.",
            );
        }

        $checkin = $booking->checkin_date->copy()->startOfDay();
        $daysOut = (int) Carbon::today()->diffInDays($checkin, absolute: false);
        $depositForfeit = $daysOut < self::POLICY_GRACE_DAYS && $booking->deposit_amount > 0;

        DB::transaction(function () use ($booking): void {
            AvailabilityBlock::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('booking_id', $booking->id)
                ->where('type', AvailabilityBlockType::Booked->value)
                ->delete();

            $booking->forceFill(['booking_status' => BookingStatus::Cancelled])->save();

            // Corporate wallet rebate. release() returns the still-
            // uncaptured hold; if the booking was already captured
            // (Confirmed/CheckedIn etc), refund() reverses the spend.
            // Both no-op cleanly for non-corporate bookings.
            $released = $this->corporates->releaseFor($booking);
            if ($released === null) {
                $this->corporates->refundFor($booking);
            }
        });

        $log = activity('booking')->performedOn($booking)->event('cancelled');
        if ($operator !== null) {
            $log->causedBy($operator);
            $actor = "Operator {$operator->name}";
        } else {
            $actor = 'Guest';
        }
        $refundNote = $refundRequested ? ' (refund requested)' : '';
        $log->withProperties([
            'days_until_checkin' => max(0, $daysOut),
            'deposit_forfeit' => $depositForfeit,
            'refund_requested' => $refundRequested,
        ])->log("{$actor} cancelled booking {$booking->booking_ref}{$refundNote}.");

        $result = new CancelBookingResult(
            booking: $booking->refresh(),
            depositForfeit: $depositForfeit,
            daysUntilCheckin: max(0, $daysOut),
        );

        // Listeners fire mail + in-dashboard notifications. Dispatched
        // AFTER the audit-log write so anything reacting to the event
        // can rely on the booking row being in its final state.
        Event::dispatch(new BookingCancelled($result->booking, $depositForfeit));

        // Bust the dashboard cache — arrivals count, in-house count,
        // outstanding total and pending-refunds badge all read from
        // the snapshot and need to reflect the cancel immediately.
        DashboardSnapshot::forget();

        return $result;
    }
}
