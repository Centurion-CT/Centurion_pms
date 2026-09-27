<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an outlet stock request.
 *
 *  Pending   → filed, waiting on the store keeper.
 *  Approved  → keeper approved; paired -N/+N ledger written and the
 *              outlet's Supply cell for the day bumps by
 *              approved_quantity.
 *  Rejected  → keeper declined (with an optional note).
 *  Cancelled → outlet withdrew before the keeper acted.
 */
enum StockRequestStatus: string
{
    case Pending   = 'pending';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Approved  => 'Approved',
            self::Rejected  => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
