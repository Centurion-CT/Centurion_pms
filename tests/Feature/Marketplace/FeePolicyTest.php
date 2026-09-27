<?php

declare(strict_types=1);

use App\Support\Marketplace\FeePolicy;

/**
 * Pins the Keverna Stays marketplace fee model in one place so
 * both the checkout preview + CreateStaysBooking override can
 * read from a stable source of truth.
 *
 * Marketplace model: flat 2.5% Keverna service fee + 1.5%
 * Paystack processing (capped ₦2,000). Both ADDED on top of the
 * room subtotal - guest sees + pays them; operator receives the
 * full room rate. Fee-tier plumbing is kept in FeePolicy in case
 * a future partner deal needs a threshold, but ships at 2.5% for
 * every subtotal today.
 */
beforeEach(function (): void {
    config()->set('keverna.pricing.paystack_fee_percent', 1.5);
    config()->set('keverna.pricing.paystack_fee_flat_ngn', 0);
    config()->set('keverna.pricing.paystack_fee_cap_ngn', 2_000);
    config()->set('keverna.pricing.stays_fee_percent_default', 2.5);
    config()->set('keverna.pricing.stays_fee_percent_high_tier', 2.5);
    config()->set('keverna.pricing.stays_fee_tier_threshold_ngn', 100_000);
});

it('charges a flat 2.5% Keverna fee on small bookings', function (): void {
    expect(FeePolicy::percentFor(50_000))->toBe(2.5);
    expect(FeePolicy::kevernaFeeFor(50_000))->toBe(1_250);
    // Operator payout = full subtotal (fees added on top for guest).
    expect(FeePolicy::operatorPayoutFor(50_000))->toBe(50_000);
});

it('holds the same 2.5% at the ₦100k threshold', function (): void {
    expect(FeePolicy::percentFor(100_000))->toBe(2.5);
    expect(FeePolicy::kevernaFeeFor(100_000))->toBe(2_500);
});

it('stays at 2.5% above the threshold (flat, no tier)', function (): void {
    expect(FeePolicy::percentFor(100_001))->toBe(2.5);
    expect(FeePolicy::kevernaFeeFor(150_000))->toBe(3_750);
    expect(FeePolicy::operatorPayoutFor(150_000))->toBe(150_000);
});

it('caps Paystack processing at ₦2,000 on a large subtotal', function (): void {
    // 1.5% of 200k = 3,000 uncapped -> ₦2,000 cap.
    expect(FeePolicy::paystackFeeFor(200_000))->toBe(2_000);
    // 1.5% of 100k = 1,500 - under cap.
    expect(FeePolicy::paystackFeeFor(100_000))->toBe(1_500);
});

it('guest total = subtotal + Keverna service fee + Paystack processing', function (): void {
    // 50k subtotal: 2.5% Keverna (1,250) + 1.5% Paystack (750),
    // both additive to guest.
    expect(FeePolicy::guestTotalFor(50_000))->toBe(50_000 + 1_250 + 750);
    // 150k: 2.5% Keverna (3,750) + Paystack 1.5% × 150k = 2,250
    // which hits the ₦2,000 cap.
    expect(FeePolicy::guestTotalFor(150_000))->toBe(150_000 + 3_750 + 2_000);
});

it('returns 0 for zero-or-negative subtotals', function (): void {
    expect(FeePolicy::kevernaFeeFor(0))->toBe(0);
    expect(FeePolicy::paystackFeeFor(0))->toBe(0);
    expect(FeePolicy::guestTotalFor(0))->toBe(0);
    expect(FeePolicy::operatorPayoutFor(0))->toBe(0);
});

it('respects env-overridden tier percents + threshold when a partner deal needs a different rate', function (): void {
    // Tier plumbing is preserved for future partner-specific
    // deals - a partner region on a 4%/2% split still resolves
    // correctly through the same FeePolicy code path.
    config()->set('keverna.pricing.stays_fee_percent_default', 4.0);
    config()->set('keverna.pricing.stays_fee_percent_high_tier', 2.0);
    config()->set('keverna.pricing.stays_fee_tier_threshold_ngn', 50_000);

    expect(FeePolicy::percentFor(40_000))->toBe(4.0);   // below threshold
    expect(FeePolicy::percentFor(60_000))->toBe(2.0);   // above threshold
    expect(FeePolicy::kevernaFeeFor(40_000))->toBe(1_600);
    expect(FeePolicy::kevernaFeeFor(60_000))->toBe(1_200);
});
