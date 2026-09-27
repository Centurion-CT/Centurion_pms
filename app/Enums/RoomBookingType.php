<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Booking modes a Room accepts (PRD §6.3.1). Mixed-mode properties (PRD §14
 * open question #6, confirmed yes) use `Both` so a single Property can house
 * nightly + monthly inventory side by side.
 */
enum RoomBookingType: string
{
    case ShortLet = 'short_let';
    case LongStay = 'long_stay';
    case Both = 'both';

    public function allowsShortLet(): bool
    {
        return $this === self::ShortLet || $this === self::Both;
    }

    public function allowsLongStay(): bool
    {
        return $this === self::LongStay || $this === self::Both;
    }
}
