<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Guest wallet ledger entry kinds.
 *
 *  Topup       — money in (cash at desk, or overpayment surplus)
 *  Spend       — money out to settle a booking / POS sale / charge
 *  Refund      — cash paid back to the guest (manager approval)
 *  Adjustment  — signed manual correction (admin only)
 *
 * The specific reason (cash_topup vs overpayment, pay_booking vs
 * pay_pos_sale, etc.) lives in `meta.source` on the transaction row —
 * one enum case per direction keeps the ledger simple; the subtype
 * badge is derived at render time.
 */
enum CustomerWalletTransactionType: string
{
    case Topup = 'topup';
    case Spend = 'spend';
    case Refund = 'refund';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Topup => 'Top-up',
            self::Spend => 'Spend',
            self::Refund => 'Refund',
            self::Adjustment => 'Adjustment',
        };
    }

    public function increasesBalance(): bool
    {
        return match ($this) {
            self::Topup, self::Refund, self::Adjustment => true,
            self::Spend => false,
        };
    }
}
