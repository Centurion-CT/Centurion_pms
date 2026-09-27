<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * KYC verification state for a Corporate. Drives the trust signal that
 * properties see on the booking flag.
 */
enum CorporateKycStatus: string
{
    case Pending  = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Pending',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }
}
