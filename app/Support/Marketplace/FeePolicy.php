<?php

declare(strict_types=1);

namespace App\Support\Marketplace;

/**
 * Single source of truth for the Keverna Stays marketplace fee
 * model. Both the checkout page's live breakdown() preview and
 * the CreateStaysBooking post-CreateBooking override read from
 * here, so a config tweak flows to both surfaces without a code
 * change + tests can assert one authoritative value.
 *
 * Model:
 *   Guest total  = room subtotal + Keverna service fee + Paystack
 *                  processing (fees are ADDED on top of the sticker
 *                  rate, all shown transparently on checkout)
 *   Keverna fee  = tiered % of room subtotal (5% on subtotals
 *                  <= 100k, 2.5% above by default). The tiered
 *                  drop keeps big-ticket bookings affordable so
 *                  guests don't route around us for extensions.
 *   Op payout    = room subtotal in full (operator keeps 100% of
 *                  the rate they set - preserves the "zero
 *                  commission" story on the operator side).
 *   Paystack fee = 1.5% of room subtotal, capped at ₦2,000
 *                  (bearer=subaccount so the operator absorbs it,
 *                   matching tenant-flow behaviour).
 */
final class FeePolicy
{
    /**
     * Guest-visible total: subtotal + Keverna service fee + Paystack
     * processing. Both fees are added on top of the sticker rate +
     * shown as separate lines on the checkout summary.
     */
    public static function guestTotalFor(int $subtotal): int
    {
        return max(0, $subtotal + self::kevernaFeeFor($subtotal) + self::paystackFeeFor($subtotal));
    }

    /**
     * Keverna's cut, tiered by subtotal. Rounded to whole NGN
     * so all downstream math (splits, payouts) stays integer-safe.
     */
    public static function kevernaFeeFor(int $subtotal): int
    {
        if ($subtotal <= 0) return 0;
        $pct = self::percentFor($subtotal);
        return (int) round($subtotal * $pct / 100);
    }

    /**
     * Which fee tier applies for this subtotal, as a percent.
     * Public so the UI can label the deduction ("5% platform fee").
     */
    public static function percentFor(int $subtotal): float
    {
        $threshold = (int) config('keverna.pricing.stays_fee_tier_threshold_ngn', 100_000);
        $high = (float) config('keverna.pricing.stays_fee_percent_high_tier', 2.5);
        $default = (float) config('keverna.pricing.stays_fee_percent_default', 5.0);

        return $subtotal > $threshold ? $high : $default;
    }

    /**
     * Paystack processing fee - 1.5% of the amount capped at ₦2,000.
     * Reads from the existing keverna.pricing config so a Paystack
     * pricing change flows uniformly across tenant + marketplace.
     */
    public static function paystackFeeFor(int $subtotal): int
    {
        if ($subtotal <= 0) return 0;
        $pct = (float) config('keverna.pricing.paystack_fee_percent', 1.5);
        $flat = (int) config('keverna.pricing.paystack_fee_flat_ngn', 0);
        $cap = (int) config('keverna.pricing.paystack_fee_cap_ngn', 2_000);
        $fee = (int) round($subtotal * $pct / 100) + $flat;
        return min($cap, $fee);
    }

    /**
     * Operator's payout - the full room subtotal. Both Keverna's
     * fee and Paystack's fee are added ON TOP of what the guest
     * pays; the operator receives 100% of their listed rate.
     * Preserved as a distinct helper so the operator dashboard's
     * "Your payout" line has a stable source (and if the model
     * ever flips, only this method changes).
     */
    public static function operatorPayoutFor(int $subtotal): int
    {
        return max(0, $subtotal);
    }
}
