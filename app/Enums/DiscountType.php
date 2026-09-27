<?php

declare(strict_types=1);

namespace App\Enums;

enum DiscountType: string
{
    case Percent = 'percent';   // value is 0-100, applied as % of netSubtotal
    case Fixed   = 'fixed';     // value is ₦ amount taken straight off

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage off',
            self::Fixed   => 'Fixed ₦ amount off',
        };
    }
}
