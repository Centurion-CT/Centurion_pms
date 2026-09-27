<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a corporate reservation window.
 *
 *  Pending    — set up but not yet within the stay window.
 *  Active     — window is current; expected members can arrive.
 *  Closed     — window has ended; still shown for reporting.
 *  Cancelled  — the corp / property backed out before or during the
 *               window; expected members can no longer be assigned.
 */
enum CorporateReservationStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case Closed    = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Active    => 'Active',
            self::Closed    => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }
}
