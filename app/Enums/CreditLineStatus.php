<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle for a house-account credit line.
 *
 *   Active    — accepts new charges + settlements.
 *   Suspended — freezes new charges; settlements still allowed
 *               (owner clearing a paused account).
 *   Closed    — fully frozen; kept for history + reporting.
 */
enum CreditLineStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function acceptsCharges(): bool
    {
        return $this === self::Active;
    }

    public function acceptsSettlements(): bool
    {
        return in_array($this, [self::Active, self::Suspended], true);
    }
}
