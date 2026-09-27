<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a residential lease contract.
 *
 *  Draft       → operator is preparing the lease document
 *  Pending     → sent to tenant, awaiting signature + deposit
 *  Active      → signed, deposit paid, tenant in possession
 *  Expired     → term lapsed without renewal
 *  Terminated  → ended early (either side)
 *  Renewed     → superseded by a newer Lease row
 */
enum LeaseStatus: string
{
    case Draft      = 'draft';
    case Pending    = 'pending';
    case Active     = 'active';
    case Expired    = 'expired';
    case Terminated = 'terminated';
    case Renewed    = 'renewed';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::Pending    => 'Pending signature',
            self::Active     => 'Active',
            self::Expired    => 'Expired',
            self::Terminated => 'Terminated',
            self::Renewed    => 'Renewed',
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
