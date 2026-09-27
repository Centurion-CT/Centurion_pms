<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often a workspace plan (or, in future, any recurring product)
 * bills. The interval also drives the customer-facing label —
 * "₦100,000 / month" vs "₦5,000 / hour".
 */
enum BillingInterval: string
{
    case Hour  = 'hour';
    case Day   = 'day';
    case Week  = 'week';
    case Month = 'month';
    case Year  = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Hour  => 'Per hour',
            self::Day   => 'Per day',
            self::Week  => 'Per week',
            self::Month => 'Per month',
            self::Year  => 'Per year',
        };
    }

    public function suffix(): string
    {
        return match ($this) {
            self::Hour  => '/hour',
            self::Day   => '/day',
            self::Week  => '/week',
            self::Month => '/month',
            self::Year  => '/year',
        };
    }

    /**
     * Whether this interval is "recurring" — i.e. it spawns a
     * Membership rather than a one-off DeskBooking. Hour/Day/Week
     * are charged per-use; Month/Year are subscriptions.
     */
    public function isRecurring(): bool
    {
        return match ($this) {
            self::Hour, self::Day, self::Week => false,
            self::Month, self::Year => true,
        };
    }
}
