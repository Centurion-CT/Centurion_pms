<?php

declare(strict_types=1);

namespace App\Site;

use App\Services\Pricing\PricingBreakdown;

/**
 * A stay's price as guests see it. `total` is what they pay, fees
 * included, so the card, the detail panel and checkout all agree.
 */
final class Quote
{
    public function __construct(
        public readonly int $nights,
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly int $fees,
        public readonly int $total,
        public readonly int $deposit,
    ) {}

    public static function from(PricingBreakdown $b): self
    {
        return new self(
            nights: $b->nights,
            subtotal: $b->subtotal,
            discount: $b->discountAmount,
            fees: $b->paystackFee + $b->kevernaFee,
            total: $b->totalDue,
            deposit: $b->depositAmount,
        );
    }

    /** Nightly rate after any discount. */
    public function perNight(): int
    {
        return (int) round(($this->subtotal - $this->discount) / max(1, $this->nights));
    }

    /** Nightly rate before the discount, when there is one. */
    public function wasPerNight(): ?int
    {
        return $this->discount > 0 ? (int) round($this->subtotal / max(1, $this->nights)) : null;
    }
}
