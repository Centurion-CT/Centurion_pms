<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a room-night is unavailable on the calendar (PRD §6.2.3):
 *  - Booked:  reserved by a guest booking (booking_id FK populated)
 *  - Blocked: held by the operator (maintenance, personal use)
 *
 * The booking engine NEVER creates Blocked rows and the operator UI never
 * creates Booked rows — that division keeps unblockRange() safe
 * (it touches only Blocked rows by design).
 */
enum AvailabilityBlockType: string
{
    case Booked = 'booked';
    case Blocked = 'blocked';
}
