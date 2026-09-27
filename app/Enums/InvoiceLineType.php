<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Categorises a single line on a guest invoice:
 *  - Night    consumed room-night(s) at the booking's nightly rate
 *  - Charge   an existing BookingCharge (room service, extension, damage…)
 *  - Package  a BookingPackage add-on (breakfast, airport pickup…)
 *  - Custom   free-form line typed by front desk (deposit forfeit, cleaning
 *             surcharge, negotiated adjustment). Not derived from any
 *             source row — description + amount are staff-authored.
 */
enum InvoiceLineType: string
{
    case Night = 'night';
    case Charge = 'charge';
    case Package = 'package';
    case Custom = 'custom';
}
