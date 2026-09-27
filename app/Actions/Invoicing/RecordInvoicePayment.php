<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\BookingCharge;
use App\Models\Invoice;
use App\Models\Operator;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Records a manual (off-Paystack) receipt against an invoice — cash,
 * transfer, POS, whatever channel the guest used. Different from
 * MarkInvoicePaid in that it captures a specific amount / method /
 * reference — supports partial payments too.
 *
 * Side effects:
 *  - Creates a Payment row (type=Invoice) tied to invoice + booking.
 *    paystack_reference is synthesised as `manual-<ulid>` so the
 *    existing unique constraint stays satisfied without touching the
 *    Paystack path.
 *  - Recomputes invoice.amount_paid as the SUM of Succeeded payments
 *    linked to the invoice (single source of truth — no drift).
 *  - Auto-flips invoice.status to Paid when amount_paid reaches total,
 *    and flips referenced BookingCharges to settled (same logic as
 *    MarkInvoicePaid, inlined here so a full RecordInvoicePayment call
 *    does the whole thing).
 */
class RecordInvoicePayment
{
    /**
     * @param  'paystack'|'cash'|'transfer'|'card'|'other'  $method
     */
    public function handle(
        Invoice $invoice,
        Operator $operator,
        int $amount,
        string $method,
        ?string $referenceNote = null,
    ): Payment {
        if ($invoice->status === InvoiceStatus::Draft) {
            throw new RuntimeException('Cannot record a payment against a draft invoice — issue it first.');
        }
        if ($invoice->status === InvoiceStatus::Void) {
            throw new RuntimeException('Cannot record a payment against a void invoice.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        return DB::transaction(function () use ($invoice, $operator, $amount, $method, $referenceNote): Payment {
            $now = Carbon::now();

            $payment = Payment::query()->create([
                'tenant_id'          => $invoice->tenant_id,
                'booking_id'         => $invoice->booking_id,
                'invoice_id'         => $invoice->id,
                'paystack_reference' => 'manual-'.Str::ulid()->toBase32(),
                'amount'             => $amount,
                'type'               => PaymentType::Invoice,
                'method'             => $method,
                'reference_note'     => $referenceNote,
                'status'             => PaymentStatus::Succeeded,
                'paid_at'            => $now,
            ]);

            // Recompute the running paid total from the ledger — never
            // trust the caller-supplied amount alone, so double-clicks
            // and stale UI state can't drift the invoice off from its
            // linked payments.
            $paidTotal = (int) Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', PaymentStatus::Succeeded)
                ->sum('amount');

            $isNowFullyPaid = $paidTotal >= $invoice->total;
            // Any payment > 0 that doesn't cover the full total flips
            // the invoice into Partial. Once Partial, subsequent
            // payments keep it Partial until the balance clears, at
            // which point it lands on Paid. Zero-payment (shouldn't
            // happen — validated above, but defensive) leaves status
            // untouched. Void is terminal — RecordInvoicePayment
            // refuses earlier so we never overwrite it here.
            $nextStatus = match (true) {
                $isNowFullyPaid       => InvoiceStatus::Paid,
                $paidTotal > 0        => InvoiceStatus::Partial,
                default               => $invoice->status,
            };

            $invoice->forceFill([
                'amount_paid' => $paidTotal,
                'status'      => $nextStatus,
                'paid_at'     => $isNowFullyPaid && $invoice->paid_at === null ? $now : $invoice->paid_at,
            ])->save();

            if ($isNowFullyPaid) {
                $this->settleReferencedCharges($invoice, $now);
            }

            activity('invoice')
                ->causedBy($operator)
                ->performedOn($invoice)
                ->event('invoice_payment_recorded')
                ->withProperties([
                    'number'       => $invoice->number,
                    'amount'       => $amount,
                    'method'       => $method,
                    'payment_id'   => $payment->id,
                    'fully_paid'   => $isNowFullyPaid,
                ])
                ->log("Operator {$operator->name} recorded {$method} payment of {$amount} against invoice {$invoice->number}.");

            return $payment->fresh();
        });
    }

    /**
     * Kept in step with MarkInvoicePaid::settleReferencedCharges — any
     * BookingCharge line-itemised on this invoice becomes settled when
     * the invoice reaches fully-paid.
     */
    private function settleReferencedCharges(Invoice $invoice, Carbon $at): void
    {
        $chargeIds = $invoice->lines()->get()
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
