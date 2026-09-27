<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Expired = 'expired';
    case Failed = 'failed';

    /**
     * Subscription rows whose presence keeps the tenant operational.
     * Used by daily lifecycle jobs to find expiry candidates.
     */
    public function isLive(): bool
    {
        return $this === self::Trial || $this === self::Active;
    }
}
