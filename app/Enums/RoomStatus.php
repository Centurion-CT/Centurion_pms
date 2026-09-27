<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a Room appears on the public booking engine (PRD §6.3.1). Inactive
 * rows stay in the dashboard but are hidden from guests — used when an
 * operator pulls a unit out of inventory temporarily without losing its
 * configuration.
 */
enum RoomStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
