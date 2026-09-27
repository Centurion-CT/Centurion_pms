<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a Corporate account in the platform.
 *
 *  Onboarding → Keverna captured the corporate but contract / KYC pending
 *  Active     → fully operational; members can book + wallet can spend
 *  Suspended  → temporary freeze (e.g. wallet abuse, contract dispute)
 *  Archived   → ended relationship; rows kept for audit
 */
enum CorporateStatus: string
{
    case Onboarding = 'onboarding';
    case Active     = 'active';
    case Suspended  = 'suspended';
    case Archived   = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Active     => 'Active',
            self::Suspended  => 'Suspended',
            self::Archived   => 'Archived',
        };
    }

    public function isLive(): bool
    {
        return $this === self::Active;
    }
}
