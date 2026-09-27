<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an operator-authored guest invoice:
 *  - Draft    staff started composing but hasn't issued it — mutable,
 *             not counted in receivables, doesn't get an invoice number
 *             yet
 *  - Issued   handed to the guest, immutable, counted in receivables,
 *             no payment recorded against it yet
 *  - Partial  payment(s) recorded but amount_paid < total, still in
 *             receivables. Transition is driven by RecordInvoicePayment
 *             / RecordCorporatePayment — never manual. Any status query
 *             that treats Issued as "still owed" must include Partial.
 *  - Paid     amount_paid >= total (staff never marks Paid manually,
 *             it's auto-set when payments cover the total)
 *  - Void     issued in error; kept for the audit trail but excluded
 *             from receivables. Number is retained (not reused).
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Partial = 'partial';
    case Paid = 'paid';
    case Void = 'void';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Void => true,
            self::Draft, self::Issued, self::Partial => false,
        };
    }

    /**
     * True while the invoice still represents outstanding receivables.
     * Query filters that would otherwise `whereIn('status', ['issued',
     * 'paid'])` should read this instead, or explicitly include Partial.
     */
    public function isReceivable(): bool
    {
        return match ($this) {
            self::Issued, self::Partial, self::Paid => true,
            self::Draft, self::Void => false,
        };
    }
}
