<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mark a booking as a no-show. Front-desk operation typically run the
 * morning after a missed check-in.
 *
 * Guards:
 *   - Booking must be in Confirmed or OnHold (a Pending booking that
 *     was never paid for never reserved anything — just cancel it).
 *   - Check-in date must be in the past or today (you can't no-show
 *     a future booking).
 *
 * Side effects:
 *   - Status → NoShow
 *   - no_show_at stamped to now
 *   - AvailabilityBlock rows for THIS booking are removed so the
 *     nights are re-sellable (we leave OTHER bookings' blocks alone
 *     in case of overlapping holds, defensive coding).
 *   - If `$forfeitDeposit` and the booking has a deposit, a
 *     DepositForfeit-type BookingCharge is recorded and marked
 *     settled (no money moves — this is a paper trail entry).
 */
class MarkNoShow
{
    /**
     * @throws RuntimeException for state/validation problems
     */
    public function handle(Booking $booking, Operator $operator, bool $forfeitDeposit = true): Booking
    {
        if (! in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::Pending], true)) {
            throw new RuntimeException(
                "Cannot mark a booking in state {$booking->booking_status->value} as no-show."
            );
        }

        $checkinDate = $booking->checkin_date->copy()->startOfDay();
        if (Carbon::today()->lt($checkinDate)) {
            throw new RuntimeException(
                'Cannot mark a future booking as a no-show — check-in date has not arrived yet.'
            );
        }

        return DB::transaction(function () use ($booking, $operator, $forfeitDeposit): Booking {
            $booking->forceFill([
                'booking_status' => BookingStatus::NoShow,
                'no_show_at' => Carbon::now(),
            ])->save();

            // Release the reserved nights so the room re-enters inventory.
            AvailabilityBlock::query()
                ->where('booking_id', $booking->id)
                ->delete();

            // Record the deposit-forfeit as a settled charge for the
            // paper trail. We don't try to settle the deposit through
            // Paystack — operators decide whether to refund manually.
            if ($forfeitDeposit && (int) $booking->deposit_amount > 0) {
                BookingCharge::query()->create([
                    'tenant_id' => $booking->tenant_id,
                    'booking_id' => $booking->id,
                    'created_by_operator_id' => $operator->getKey(),
                    'type' => BookingChargeType::DepositForfeit->value,
                    'description' => 'Deposit forfeit · no-show on '.Carbon::now()->toFormattedDateString(),
                    'amount' => (int) $booking->deposit_amount,
                    'settled' => true,
                    'settled_at' => Carbon::now(),
                    'settled_method' => 'deposit_forfeit',
                ]);
            }

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('no_show')
                ->log(sprintf(
                    'Operator %s marked %s (%s) as no-show.',
                    $operator->name,
                    $booking->booking_ref,
                    $booking->guest_name,
                ));

            return $booking->refresh();
        });
    }
}
