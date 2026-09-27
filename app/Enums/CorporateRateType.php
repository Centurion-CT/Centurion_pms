<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a negotiated corporate rate modifies the public price.
 *
 *  PercentOff  — `value` = % discount (10 = 10% off)
 *  FixedOff    — `value` = ₦ amount subtracted per night/unit
 *  FixedPrice  — `value` = ₦ amount that replaces the public price
 */
enum CorporateRateType: string
{
    case PercentOff = 'percent_off';
    case FixedOff   = 'fixed_off';
    case FixedPrice = 'fixed_price';

    public function label(): string
    {
        return match ($this) {
            self::PercentOff => '% off',
            self::FixedOff   => '₦ off',
            self::FixedPrice => 'Flat ₦ price',
        };
    }

    /**
     * Apply this rate against a public unit price (in ₦, integer).
     */
    public function apply(int $publicPrice, int $value): int
    {
        return match ($this) {
            self::PercentOff => max(0, (int) round($publicPrice * (100 - $value) / 100)),
            self::FixedOff   => max(0, $publicPrice - $value),
            self::FixedPrice => max(0, $value),
        };
    }
}
