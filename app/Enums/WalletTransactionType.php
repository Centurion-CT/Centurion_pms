<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wallet ledger entry kinds.
 *
 *  Topup       — money in from the corporate (Paystack or recorded bank)
 *  Hold        — funds reserved by a pending booking (not yet spent)
 *  Release     — hold released back to available balance (cancelled before capture)
 *  Spend       — hold converted to a captured spend (booking confirmed)
 *  Refund      — spend reversed back to the wallet (cancelled after capture)
 *  Adjustment  — manual correction by a Keverna admin (signed)
 */
enum WalletTransactionType: string
{
    case Topup      = 'topup';
    case Hold       = 'hold';
    case Release    = 'release';
    case Spend      = 'spend';
    case Refund     = 'refund';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Topup      => 'Top-up',
            self::Hold       => 'Hold',
            self::Release    => 'Release',
            self::Spend      => 'Spend',
            self::Refund     => 'Refund',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Whether this transaction increases the spendable balance.
     */
    public function increasesAvailable(): bool
    {
        return match ($this) {
            self::Topup, self::Release, self::Refund, self::Adjustment => true,
            self::Hold, self::Spend => false,
        };
    }
}
