<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a workspace subscription — used by the Memberships
 * list filter + the public member-portal visibility check.
 */
enum MembershipStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case Paused    = 'paused';
    case Cancelled = 'cancelled';
    case Expired   = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Active    => 'Active',
            self::Paused    => 'Paused',
            self::Cancelled => 'Cancelled',
            self::Expired   => 'Expired',
        };
    }

    public function isLive(): bool
    {
        return $this === self::Active || $this === self::Pending;
    }

    /**
     * @return array<string,string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }

        return $out;
    }
}
