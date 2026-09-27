<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of one scheduled rent invoice on a lease.
 */
enum LeasePaymentStatus: string
{
    case Upcoming = 'upcoming';
    case Due      = 'due';
    case Overdue  = 'overdue';
    case Paid     = 'paid';
    case Waived   = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'Upcoming',
            self::Due      => 'Due',
            self::Overdue  => 'Overdue',
            self::Paid     => 'Paid',
            self::Waived   => 'Waived',
        };
    }
}
