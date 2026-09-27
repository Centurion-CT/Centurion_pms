<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\ManualDiscountType;
use App\Models\Booking;
use App\Models\Operator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Manager-granted discount applied AFTER the guest has checked in
 * (or is confirmed / on-hold) — think "loyal repeat guest, take
 * 15% off tonight" or "the AC failed for a night, knock ₦20,000
 * off." Distinct from the manual discount stamped at booking
 * creation (which is what's already priced into total_amount).
 *
 * Effect on the ledger:
 *   - Reduces booking.total_amount by the resolved ₦ off.
 *   - Reduces booking.balance_due when there's still money owed;
 *     if the guest had already overpaid we leave balance_due at 0
 *     (surplus stays on the wallet — RecordManualPayment already
 *     credits over-collection).
 *   - Increments manual_discount_amount so the reservation-show
 *     tile shows the CUMULATIVE cut rather than only what was
 *     baked in at booking time.
 *   - Logs a `post_checkin_discount_applied` activity_log row with
 *     causer = operator and properties = {mode, percent, amount,
 *     reason, total_before, total_after}. That's the record the
 *     payments page + booking timeline read from.
 */
class ApplyPostCheckinDiscount
{
    public function handle(
        Booking $booking,
        Operator $operator,
        ManualDiscountType $mode,
        float $percent = 0.0,
        int $fixedAmount = 0,
        ?string $reason = null,
    ): Booking {
        $totalBefore = (int) $booking->total_amount;
        if ($totalBefore <= 0) {
            throw new InvalidArgumentException('This booking has nothing left to discount.');
        }

        // Resolve ₦ off from the mode. Percent floors so we always
        // land on whole naira; fixed clamps to the booking total so
        // total_amount never goes negative.
        $amountOff = match ($mode) {
            ManualDiscountType::Percent => (int) floor($totalBefore * max(0.0, min(100.0, $percent)) / 100),
            ManualDiscountType::Fixed   => max(0, min($fixedAmount, $totalBefore)),
        };
        if ($amountOff <= 0) {
            throw new InvalidArgumentException('Discount must be greater than ₦0.');
        }

        $totalAfter = max(0, $totalBefore - $amountOff);
        // If balance_due tracks money still owed, take the discount
        // off there first — otherwise (guest already paid) we just
        // drop total_amount and leave balance_due at 0.
        $newBalanceDue = max(0, (int) $booking->balance_due - $amountOff);

        DB::transaction(function () use ($booking, $totalAfter, $newBalanceDue, $amountOff, $mode, $percent, $reason, $operator, $totalBefore): void {
            $booking->forceFill([
                'total_amount' => $totalAfter,
                'balance_due'  => $newBalanceDue,
                'manual_discount_amount' => (int) $booking->manual_discount_amount + $amountOff,
                // The manual_discount_by_operator_id already tracks
                // the original applier; the post-checkin trail lives
                // on activity_log so we don't overwrite that field.
            ])->save();

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('post_checkin_discount_applied')
                ->withProperties([
                    'booking_ref'  => $booking->booking_ref,
                    'mode'         => $mode->value,
                    'percent'      => $mode === ManualDiscountType::Percent
                        ? round(max(0.0, min(100.0, $percent)), 2)
                        : null,
                    'amount'       => $amountOff,
                    'reason'       => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                    'total_before' => $totalBefore,
                    'total_after'  => $totalAfter,
                    'applied_at'   => Carbon::now()->toIso8601String(),
                ])
                ->log(sprintf(
                    'Operator %s applied a %s discount of ₦%s post check-in.',
                    $operator->name,
                    $mode === ManualDiscountType::Percent
                        ? rtrim(rtrim(number_format($percent, 2), '0'), '.').'%'
                        : 'flat',
                    number_format($amountOff),
                ));
        });

        return $booking->refresh();
    }
}
