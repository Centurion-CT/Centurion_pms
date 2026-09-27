<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Corporate;
use App\Models\CorporatePayment;
use App\Models\CorporatePaymentAllocation;
use App\Models\Invoice;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Corporates\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money the corporate has sent us — record it against their unpaid
 * invoices oldest-first (FIFO). One incoming payment can split across
 * multiple invoices; each split lands as a CorporatePaymentAllocation
 * row so the audit trail can trace every naira back to the invoice it
 * settled.
 *
 * Invoice.amount_paid + status get updated for every invoice touched.
 * When a payment exceeds the total outstanding (an overpayment) the
 * excess parks on the CorporatePayment row itself — reflected via
 * "unallocated" (amount_naira − sum(allocations.amount_naira)) — and
 * gets consumed by the next invoice the corp lands.
 *
 * Runs in a DB transaction: a partial failure (e.g. an FK collision
 * mid-loop) rolls back the payment + all allocations so the ledger
 * stays consistent.
 */
class RecordCorporatePayment
{
    /**
     * @param  'cash'|'transfer'|'card'|'pos'|'other'|'wallet'  $method
     * @param  ?int  $targetInvoiceId  When set, that invoice is
     *   settled before FIFO kicks in for any remainder. Used by the
     *   ledger's per-row "Pay" button so operators can settle a
     *   specific invoice without overpaying an older one.
     *
     * `wallet` draws from the corporate's balance via WalletService::
     * spend() before the FIFO invoice offset runs — refuses when the
     * wallet doesn't have enough available (balance − held).
     */
    public function handle(
        Corporate $corporate,
        Operator $operator,
        int $amount,
        string $method,
        ?Carbon $paidAt = null,
        ?string $reference = null,
        ?string $note = null,
        ?string $receiptPath = null,
        ?int $targetInvoiceId = null,
    ): CorporatePayment {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than ₦0.');
        }
        if (! in_array($method, ['cash', 'transfer', 'card', 'pos', 'other', 'wallet'], true)) {
            throw new RuntimeException('Pick a valid payment method.');
        }

        // Wallet source: pre-flight the balance so we can refuse with
        // a friendly message instead of erroring mid-transaction. The
        // actual debit happens inside the DB::transaction below so a
        // later failure rolls both back together.
        if ($method === 'wallet') {
            $wallet = app(WalletService::class)->ensureWallet($corporate);
            $available = (int) $wallet->balance_naira - (int) $wallet->held_naira;
            if ($available < $amount) {
                throw new RuntimeException(sprintf(
                    'Wallet has ₦%s available. Top up before recording ₦%s.',
                    number_format(max(0, $available)),
                    number_format($amount),
                ));
            }
        }

        return DB::transaction(function () use (
            $corporate, $operator, $amount, $method, $paidAt, $reference, $note, $receiptPath, $targetInvoiceId
        ): CorporatePayment {
            $payment = CorporatePayment::query()->create([
                'corporate_id' => $corporate->id,
                'recorded_by_operator_id' => $operator->getKey(),
                'amount_naira' => $amount,
                'method' => $method,
                'paid_at' => $paidAt ?? Carbon::now(),
                'reference' => $reference,
                'note' => $note,
                'receipt_path' => $receiptPath,
            ]);

            // Wallet-source payments: hold + spend in one shot so the
            // ledger reads as a clean two-step (available → held →
            // captured) instead of a raw balance decrement. Both
            // entries link back to the CorporatePayment row via meta
            // so the wallet-transactions view can jump straight to it.
            if ($method === 'wallet') {
                $walletService = app(WalletService::class);
                $walletMeta = [
                    'corporate_payment_id' => $payment->id,
                    'target_invoice_id' => $targetInvoiceId,
                ];
                $walletService->hold(
                    corporate: $corporate,
                    amount: $amount,
                    description: 'Hold for corp invoice payment #'.$payment->id,
                    meta: $walletMeta,
                    causer: $operator,
                );
                $walletService->spend(
                    corporate: $corporate,
                    amount: $amount,
                    description: 'Wallet debit for corp invoice payment #'.$payment->id,
                    meta: $walletMeta,
                    causer: $operator,
                );
            }

            // Walk unpaid corporate invoices oldest-first. "Unpaid" =
            // Issued status AND amount_paid < total. Draft invoices are
            // skipped (not yet a live receivable). Void invoices are
            // skipped (no longer collectable).
            //
            // When $targetInvoiceId is set (operator clicked Pay on a
            // specific ledger row) we hoist that row to the front of
            // the walk so the money lands where they picked it. Any
            // overflow still falls to FIFO on the rest.
            // Both Issued (untouched) and Partial (some payment
            // already applied) are receivables the corp still owes.
            // Draft isn't a live receivable, Void is reversed, Paid
            // is done — all three are excluded.
            $remaining = $amount;
            $unpaidInvoices = Invoice::query()
                ->where('corporate_id', $corporate->id)
                ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Partial])
                ->whereColumn('amount_paid', '<', 'total')
                ->orderBy('issued_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($targetInvoiceId !== null) {
                $unpaidInvoices = $unpaidInvoices
                    ->sortByDesc(fn (Invoice $inv) => $inv->id === $targetInvoiceId ? 1 : 0)
                    ->values();
            }

            foreach ($unpaidInvoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }
                $needs = (int) $invoice->total - (int) $invoice->amount_paid;
                if ($needs <= 0) {
                    continue;
                }
                $apply = min($remaining, $needs);
                $newPaid = (int) $invoice->amount_paid + $apply;
                $isSettled = $newPaid >= (int) $invoice->total;

                // Partial when payment landed but didn't clear the
                // balance. Paid once amount_paid >= total. Never
                // downgrade a Partial back to Issued — a payment can
                // only ever move the status forward.
                $nextStatus = match (true) {
                    $isSettled       => InvoiceStatus::Paid,
                    $newPaid > 0     => InvoiceStatus::Partial,
                    default          => $invoice->status,
                };
                $invoice->forceFill([
                    'amount_paid' => $newPaid,
                    'status' => $nextStatus,
                    'paid_at' => $isSettled ? Carbon::now() : $invoice->paid_at,
                ])->save();

                CorporatePaymentAllocation::query()->create([
                    'corporate_payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount_naira' => $apply,
                ]);
                $remaining -= $apply;
            }

            // Mirror the receipt onto the tenant-wide Payments ledger so
            // corp AR cash shows up on /payments + on the daily-collected
            // KPIs alongside guest folio payments. The CorporatePayment
            // row remains the AR source-of-truth; this Payment row is a
            // read-model for the operator's cash view.
            $tenantId = (int) (Tenant::current()?->getKey() ?? 0);
            if ($tenantId > 0) {
                Payment::query()->create([
                    'tenant_id' => $tenantId,
                    'booking_id' => null,
                    'corporate_id' => $corporate->id,
                    'corporate_payment_id' => $payment->id,
                    'paystack_reference' => 'CORP-'.$payment->id,
                    'amount' => $amount,
                    'type' => PaymentType::CorporateAr,
                    'method' => $method,
                    'collected_by_operator_id' => $operator->getKey(),
                    'reference_note' => $reference ?? $note,
                    'status' => PaymentStatus::Succeeded,
                    'paid_at' => $paidAt ?? Carbon::now(),
                ]);
            }

            // Any leftover money sits unallocated on the payment row.
            // Surfaces on the ledger UI as a "credit" the next invoice
            // will draw down.
            return $payment->refresh();
        });
    }
}
