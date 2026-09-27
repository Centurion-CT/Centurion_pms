<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * "Guest is leaving before their booked checkout — how many nights
 * are unused and how much did they pay for those nights?"
 *
 * MarkCheckedOut used to silently keep the money as revenue with no
 * refund path. Now the front desk sees this quote at checkout time
 * and picks how to handle it: keep, wallet-credit, or cash refund.
 *
 * Amount math is intentionally simple — average per-night rate from
 * `room_total ÷ nights` times the unused count. This mixes tiers on
 * a stay that spans base + breakfast nights, but that matches how
 * the operator remembers the booking ("₦42,500/night on average").
 * Refund is capped at what's actually been paid — a guest who owes
 * money still doesn't get a refund; the outstanding guard fires first.
 */
class EarlyCheckoutQuote
{
    /**
     * @param  int  $refundPercent  0-100. Applies the property's
     *  cancellation-fee percent to the unused amount (e.g. 50 = half
     *  refund, 100 = full refund of unused nights). Default 100
     *  preserves prior behaviour for callers that don't know about
     *  the policy yet.
     *
     * @return array{
     *   is_early: bool,
     *   unused_nights: int,
     *   per_night: int,
     *   unused_amount: int,
     *   refundable_amount: int,
     *   refund_percent: int,
     *   booked_nights: int,
     *   actual_nights: int,
     * }
     */
    public function quote(Booking $booking, ?Carbon $asOf = null, int $refundPercent = 100): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $bookedNights = max(0, (int) ($booking->nights ?? 0));
        $refundPercent = max(0, min(100, $refundPercent));

        // "Nights physically stayed" = min(today, booked checkout) − checkin.
        // A guest leaving on their booked day contributes bookedNights
        // (not early); a guest overstaying contributes bookedNights too
        // (overstay is a separate flow in MarkCheckedOut).
        $checkin = $booking->checkin_date?->copy()->startOfDay();
        $checkout = $booking->checkout_date?->copy()->startOfDay();
        if ($checkin === null || $checkout === null || $bookedNights === 0) {
            return $this->none($bookedNights, 0, $refundPercent);
        }

        $effectiveCheckout = $asOf->lessThan($checkout) ? $asOf : $checkout;
        $actualNights = max(0, (int) $checkin->diffInDays($effectiveCheckout, absolute: false));
        $unusedNights = max(0, $bookedNights - $actualNights);

        if ($unusedNights === 0) {
            return $this->none($bookedNights, $actualNights, $refundPercent);
        }

        $perNight = intdiv((int) ($booking->room_total ?? 0), max(1, $bookedNights));
        $unusedAmount = $perNight * $unusedNights;

        // Apply the property's cancellation-fee percent, then cap the
        // result at what was actually paid. Order matters: a partially-
        // paid guest still can't be refunded more than they gave us,
        // even if the policy is 100%.
        $policyAmount = intdiv($unusedAmount * $refundPercent, 100);
        $paidTotal = (int) $booking->payments()
            ->where('status', \App\Enums\PaymentStatus::Succeeded->value)
            ->sum('amount');
        $refundableAmount = max(0, min($policyAmount, $paidTotal));

        return [
            'is_early' => true,
            'unused_nights' => $unusedNights,
            'per_night' => $perNight,
            'unused_amount' => $unusedAmount,
            'refundable_amount' => $refundableAmount,
            'refund_percent' => $refundPercent,
            'booked_nights' => $bookedNights,
            'actual_nights' => $actualNights,
        ];
    }

    /**
     * @return array{is_early: bool, unused_nights: int, per_night: int, unused_amount: int, refundable_amount: int, refund_percent: int, booked_nights: int, actual_nights: int}
     */
    private function none(int $bookedNights = 0, int $actualNights = 0, int $refundPercent = 100): array
    {
        return [
            'is_early' => false,
            'unused_nights' => 0,
            'per_night' => 0,
            'unused_amount' => 0,
            'refundable_amount' => 0,
            'refund_percent' => $refundPercent,
            'booked_nights' => $bookedNights,
            'actual_nights' => $actualNights,
        ];
    }
}
