<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerInteractionDirection: string
{
    case Inbound  = 'inbound';   // customer reached out to us
    case Outbound = 'outbound';  // we reached out to the customer
    case None     = 'none';      // notes, system events — no direction

    public function label(): string
    {
        return match ($this) {
            self::Inbound  => 'Inbound',
            self::Outbound => 'Outbound',
            self::None     => '',
        };
    }
}
