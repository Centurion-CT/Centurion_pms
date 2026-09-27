<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Full cost breakdown for a booking (PRD §6.4.2). All amounts are integer
 * NGN — Naira sub-units are essentially unused so we sidestep float math.
 *
 * `operator_payout` is what the operator actually receives — equal to the
 * post-discount subtotal because both fees are added on top of the guest
 * total (PRD §4.1).
 */
final readonly class PricingBreakdown
{
    /**
     * @param  list<NightlyRate>  $nightlyRates
     */
    public function __construct(
        public int $nights,
        public array $nightlyRates,
        public int $subtotal,            // before discount, after per-night sum / monthly calc
        public int $discountPercent,     // 0 when no auto discount kicks in
        public int $discountAmount,      // subtotal * discountPercent / 100, rounded
        public int $netSubtotal,         // subtotal - discountAmount + packagesTotal
        public int $depositAmount,       // hold-deposit amount per Room config
        public int $paystackFee,         // capped, additive on guest total
        public int $kevernaFee,          // capped, additive on guest total
        public int $totalDue,            // netSubtotal + fees — what the guest pays
        public int $operatorPayout,      // == netSubtotal (operator gets room rate + packages in full)
        public bool $longStayPricing,    // true when monthly_rate was used
        public int $packagesTotal = 0,   // sum of paid packages selected during booking
    ) {}
}
