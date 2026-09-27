<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a residential rental unit.
 */
enum UnitStatus: string
{
    case Available   = 'available';
    case Leased      = 'leased';
    case Maintenance = 'maintenance';
    case Archived    = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Available   => 'Available',
            self::Leased      => 'Leased',
            self::Maintenance => 'Maintenance',
            self::Archived    => 'Archived',
        };
    }
}
