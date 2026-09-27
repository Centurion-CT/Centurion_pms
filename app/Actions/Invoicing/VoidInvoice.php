<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Operator;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Voids an invoice. Numbering is NOT reclaimed — voided invoices keep
 * their sequence + number so the run remains gap-free for auditors.
 */
class VoidInvoice
{
    public function handle(Invoice $invoice, Operator $operator, ?string $reason = null): void
    {
        if ($invoice->status === InvoiceStatus::Paid) {
            throw new RuntimeException('Cannot void a paid invoice — issue a refund instead.');
        }
        if ($invoice->status === InvoiceStatus::Void) {
            return;
        }

        $invoice->forceFill([
            'status'    => InvoiceStatus::Void,
            'voided_at' => Carbon::now(),
        ])->save();

        activity('invoice')
            ->causedBy($operator)
            ->performedOn($invoice)
            ->event('invoice_voided')
            ->withProperties([
                'number' => $invoice->number,
                'reason' => $reason,
            ])
            ->log("Operator {$operator->name} voided invoice {$invoice->number}.");
    }
}
