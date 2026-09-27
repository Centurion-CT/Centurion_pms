<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Grace = 'grace';
    case Locked = 'locked';
    case Archived = 'archived';

    public function isOperational(): bool
    {
        return match ($this) {
            self::Trial, self::Active, self::Grace => true,
            self::Locked, self::Archived => false,
        };
    }

    public function canAcceptBookings(): bool
    {
        // PRD §4.2: locked tenants show "temporarily unavailable" on public pages.
        return $this === self::Trial || $this === self::Active;
    }
}
