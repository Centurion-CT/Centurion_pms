<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\Property;
use Illuminate\Support\Carbon;

/**
 * Given a booking + the current time, decide whether a late-checkout
 * fee applies and how much.
 *
 * Only fires on same-day late checkouts - i.e. today equals the booked
 * checkout_date but the guest is leaving past the property's advertised
 * check-out window. Overnight overstays are still handled by
 * MarkCheckedOut's overstay path (nightly rate × extra nights), so the
 * two never double-count each other.
 *
 * The policy shape:
 *   - `late_checkout_enabled`               master switch
 *   - `late_checkout_grace_minutes`         wall-clock buffer past check_out_to
 *                                           before the meter starts (e.g. 30)
 *   - `late_checkout_hourly_fee`            naira per started hour late
 *   - `late_checkout_full_day_after_hours`  N hours late trips a full room-night
 *                                           charge instead of hourly. 0 = never.
 */
class LateCheckoutFeeCalculator
{
    /**
     * @return array{
     *     kind: 'none'|'hourly'|'full_day',
     *     amount: int,
     *     hours_late: int,
     *     description: string
     * }
     */
    public function quote(Booking $booking, Property $property, Carbon $now): array
    {
        $none = [
            'kind' => 'none',
            'amount' => 0,
            'hours_late' => 0,
            'description' => '',
        ];

        // Prefer the policy snapshot frozen at check-in. Booking-less
        // helpers fall through to the property's live config. Once the
        // snapshot is set, later policy changes don't retroactively
        // rewrite the late fee this guest sees.
        $policy = $booking->effectiveLateCheckoutPolicy();
        if (empty($policy['late_checkout_enabled'])) {
            return $none;
        }

        $checkoutTo = (string) ($policy['check_out_to'] ?? '');
        if ($checkoutTo === '') {
            // No configured check-out time to measure against - nothing to bill.
            return $none;
        }

        // Only same-day late - an overnight guest is an "overstay",
        // handled by MarkCheckedOut against the nightly base_rate.
        $today = $now->copy()->startOfDay();
        $checkoutDate = $booking->checkout_date->copy()->startOfDay();
        if (! $today->equalTo($checkoutDate)) {
            return $none;
        }

        // Latest allowed moment = today's check_out_to + grace.
        [$hh, $mm] = array_pad(explode(':', $checkoutTo), 2, '0');
        $deadline = $now->copy()
            ->startOfDay()
            ->setTime((int) $hh, (int) $mm)
            ->addMinutes((int) ($policy['late_checkout_grace_minutes'] ?? 0));

        if ($now->lessThanOrEqualTo($deadline)) {
            return $none;
        }

        // Ceil to whole started hours - half an hour late is still an
        // hour billed. Operators reason about "hours" not "minutes".
        $minutesLate = (int) $deadline->diffInMinutes($now, absolute: true);
        $hoursLate = (int) ceil($minutesLate / 60);
        if ($hoursLate < 1) {
            $hoursLate = 1;
        }

        $fullDayThreshold = (int) ($policy['late_checkout_full_day_after_hours'] ?? 0);
        // Full-day fallback charges at the tier the guest committed
        // to (breakfast tier or base) so a breakfast guest hitting
        // the full-day threshold doesn't get a cheaper fee than one
        // extra committed night.
        $roomRate = $booking->committedNightlyRate();

        if ($fullDayThreshold > 0
            && $hoursLate >= $fullDayThreshold
            && $roomRate > 0) {
            return [
                'kind' => 'full_day',
                'amount' => $roomRate,
                'hours_late' => $hoursLate,
                'description' => sprintf(
                    'Late check-out · %d hour%s past %s → full-day rate',
                    $hoursLate,
                    $hoursLate === 1 ? '' : 's',
                    $checkoutTo,
                ),
            ];
        }

        $hourly = (int) ($policy['late_checkout_hourly_fee'] ?? 0);
        if ($hourly <= 0) {
            return $none;
        }

        return [
            'kind' => 'hourly',
            'amount' => $hourly * $hoursLate,
            'hours_late' => $hoursLate,
            'description' => sprintf(
                'Late check-out · %d hour%s past %s @ ₦%s/hr',
                $hoursLate,
                $hoursLate === 1 ? '' : 's',
                $checkoutTo,
                number_format($hourly),
            ),
        ];
    }
}
