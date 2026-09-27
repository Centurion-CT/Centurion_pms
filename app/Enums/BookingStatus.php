<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a Booking row (PRD §6.2.2 + §6.4.3 + §11):
 *  - Pending      created, waiting for Paystack to confirm
 *  - Confirmed    full payment captured
 *  - OnHold       deposit captured; balance due at deadline
 *  - Cancelled    operator or guest cancelled (with or without refund flag)
 *  - CheckedIn    operator marked the guest as arrived
 *  - CheckedOut   operator marked the guest as departed
 *  - NoShow       check-in date passed without the guest arriving —
 *                 the room is released for re-sale, deposit is forfeit
 *                 by default (operator can refund manually if needed)
 */
enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case NoShow = 'no_show';

    /**
     * Statuses that hold inventory — the AvailabilityBlock rows must exist
     * for these. Cancelled, CheckedOut and NoShow release the inventory.
     */
    public function holdsInventory(): bool
    {
        return match ($this) {
            self::Pending, self::Confirmed, self::OnHold, self::CheckedIn => true,
            self::Cancelled, self::CheckedOut, self::NoShow => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::Confirmed  => 'Confirmed',
            self::OnHold     => 'On hold',
            self::Cancelled  => 'Cancelled',
            self::CheckedIn  => 'Checked in',
            self::CheckedOut => 'Checked out',
            self::NoShow     => 'No show',
        };
    }
}
