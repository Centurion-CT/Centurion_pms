<?php

declare(strict_types=1);

namespace App\Enums;

enum CorporateMemberStatus: string
{
    case Invited    = 'invited';
    case Active     = 'active';
    case Suspended  = 'suspended';
    case Offboarded = 'offboarded';

    public function label(): string
    {
        return match ($this) {
            self::Invited    => 'Invited',
            self::Active     => 'Active',
            self::Suspended  => 'Suspended',
            self::Offboarded => 'Offboarded',
        };
    }

    public function canBook(): bool
    {
        return $this === self::Active;
    }
}
