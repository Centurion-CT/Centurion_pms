<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a POS sale.
 *
 *  - Draft     : cart is open, no stock decrement or payment yet.
 *                Editable. Auto-cleaned by a housekeeping job after
 *                24h of inactivity so abandoned tabs don't hoard
 *                inventory in phantom reservations.
 *  - Completed : payment recorded, stock decremented. Immutable.
 *  - Voided    : reversed after completion — stock restored, payment
 *                marked refunded. Reason captured on the sale.
 */
enum PosSaleStatus: string
{
    case Draft     = 'draft';
    case Completed = 'completed';
    case Voided    = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Completed => 'Completed',
            self::Voided    => 'Voided',
        };
    }
}
