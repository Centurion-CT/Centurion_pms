<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a corporate rate applies. Resolution order at booking time is
 * most-specific-first: Unit → Workspace → RoomCategory → Property → Global.
 */
enum CorporateRateScope: string
{
    case Global       = 'global';
    case Property     = 'property';
    case RoomCategory = 'room_category';
    case Workspace    = 'workspace';
    case Unit         = 'unit';

    public function label(): string
    {
        return match ($this) {
            self::Global       => 'Global (everywhere on platform)',
            self::Property     => 'Property',
            self::RoomCategory => 'Room category',
            self::Workspace    => 'Workspace',
            self::Unit         => 'Residential unit',
        };
    }

    public function requiresScopeId(): bool
    {
        return $this !== self::Global;
    }
}
