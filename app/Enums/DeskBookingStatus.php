<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * State machine for one-off hourly/day-pass workspace reservations.
 * Mirrors BookingStatus but for desk-level inventory.
 */
enum DeskBookingStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case InUse     = 'in_use';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow    = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Confirmed => 'Confirmed',
            self::InUse     => 'In use',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow    => 'No-show',
        };
    }
}
