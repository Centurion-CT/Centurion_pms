<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Models\BookingCharge;
use App\Models\Invoice;
use App\Models\Operator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Front desk marks an issued invoice as paid — cash / transfer / card /
 * whatever off-platform channel was used. We do NOT auto-reconcile
 * against Paystack yet (payments there are booked against the parent
 * Booking directly); this action just flips the invoice's status +
 * bumps amount_paid to total.
 *
 * Side effect: any BookingCharge that was line-itemised into this
 * invoice gets flipped to settled=true in the same transaction — the
 * guest paid the invoice, so they've paid those charges. Otherwise
 * the booking's outstanding-balance calc would double-count them.
 */
class MarkInvoicePaid
{
    public function handle(Invoice $invoice, Operator $operator): void
    {
        if ($invoice->status === InvoiceStatus::Draft) {
            throw new RuntimeException('Draft invoice — issue it first before marking paid.');
        }
        if ($invoice->status === InvoiceStatus::Void) {
            throw new RuntimeException('Void invoice cannot be marked paid.');
        }
        if ($invoice->status === InvoiceStatus::Paid) {
            // Idempotent no-op — clicking "mark paid" twice shouldn't error.
            return;
        }

        DB::transaction(function () use ($invoice, $operator): void {
            $now = Carbon::now();

            $invoice->forceFill([
                'status'      => InvoiceStatus::Paid,
                'amount_paid' => $invoice->total,
                'paid_at'     => $now,
            ])->save();

            $this->settleReferencedCharges($invoice, $now);
        });

        activity('invoice')
            ->causedBy($operator)
            ->performedOn($invoice)
            ->event('invoice_paid')
            ->withProperties(['number' => $invoice->number, 'total' => $invoice->total])
            ->log("Operator {$operator->name} marked invoice {$invoice->number} paid.");
    }

    /**
     * Flip every BookingCharge referenced by a Charge-typed line to
     * settled. `settled_method` records which invoice did it so the
     * activity trail is traceable in both directions.
     */
    private function settleReferencedCharges(Invoice $invoice, Carbon $at): void
    {
        $chargeIds = $invoice->lines
            ->where('line_type', InvoiceLineType::Charge)
            ->where('source_type', BookingCharge::class)
            ->pluck('source_id')
            ->filter()
            ->all();

        if ($chargeIds === []) {
            return;
        }

        BookingCharge::query()
            ->whereIn('id', $chargeIds)
            ->where('settled', false)
            ->update([
                'settled'        => true,
                'settled_at'     => $at,
                'settled_method' => 'invoice:'.($invoice->number ?? "#{$invoice->id}"),
            ]);
    }
}
