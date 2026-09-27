<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persists an invoice + its lines for one booking.
 *
 * Called after front desk has finalised the line set in the Livewire
 * create form. Runs inside a DB transaction that:
 *   - allocates the next per-tenant sequence via a row-locked SELECT
 *     MAX(sequence) — sufficient for MVP scale; a genuine advisory
 *     lock is a follow-up if we ever see contention
 *   - snapshots the tenant's paystack_* payout account onto the row
 *     (bank_*), so a later payout-account change never rewrites what
 *     the guest was told to pay into
 *   - snapshots the guest contact fields off the booking for the
 *     same immutability reason
 *
 * `issue = false` produces a Draft (no number, no sequence, no
 * issued_at). Callers that already have the numbers finalised pass
 * `issue = true` (the common path).
 */
class CreateInvoice
{
    /**
     * @param  list<array{
     *     line_type: string,
     *     source_type?: ?string,
     *     source_id?: ?int,
     *     description: string,
     *     quantity: int,
     *     unit_price: int,
     * }>  $lines
     */
    public function handle(
        Tenant $tenant,
        Booking $booking,
        Operator $operator,
        array $lines,
        ?string $notes = null,
        ?Carbon $dueAt = null,
        bool $issue = true,
        int $initialAmountPaid = 0,
    ): Invoice {
        if ($lines === []) {
            throw new InvalidArgumentException('An invoice needs at least one line.');
        }

        return DB::transaction(function () use ($tenant, $booking, $operator, $lines, $notes, $dueAt, $issue, $initialAmountPaid): Invoice {
            $now = Carbon::now();

            // Compute totals up front so subtotal + total agree with the
            // sum of the line rows we're about to persist.
            $subtotal = 0;
            $normalisedLines = [];
            foreach ($lines as $raw) {
                $qty = max(1, (int) $raw['quantity']);
                $unit = (int) $raw['unit_price'];
                $lineTotal = $qty * $unit;
                $subtotal += $lineTotal;

                $normalisedLines[] = [
                    'line_type' => $raw['line_type'],
                    'source_type' => $raw['source_type'] ?? null,
                    'source_id' => $raw['source_id'] ?? null,
                    'description' => $raw['description'],
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ];
            }

            $sequence = null;
            $number = null;
            if ($issue) {
                // Serialize concurrent invoice creations for this tenant
                // by locking the tenant row itself. `SELECT MAX() FOR
                // UPDATE` looks natural but Postgres rejects it (aggregate
                // + row lock = no single row to lock) — MySQL happens to
                // accept it. Locking the tenant row is cross-DB safe and
                // gives the same guarantee: only one invoice-issuance
                // transaction per tenant proceeds at a time; concurrent
                // issues for different tenants stay parallel.
                Tenant::query()
                    ->whereKey($tenant->id)
                    ->lockForUpdate()
                    ->first();

                $currentMax = (int) Invoice::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereNotNull('sequence')
                    ->max('sequence');
                $sequence = $currentMax + 1;
                $number = sprintf('INV-%d-%06d', (int) $now->year, $sequence);
            }

            $invoice = Invoice::query()->create([
                'tenant_id' => $tenant->id,
                'booking_id' => $booking->id,
                'customer_id' => $booking->customer_id,
                // Denormalised from the booking so the Corporate Ledger
                // can list every corp invoice via a single indexed query.
                'corporate_id' => $booking->corporate_id,
                'issued_by_operator_id' => $operator->id,
                'sequence' => $sequence,
                'number' => $number,
                'status' => $issue ? InvoiceStatus::Issued : InvoiceStatus::Draft,
                'guest_name' => $booking->guest_name,
                'guest_email' => $booking->guest_email,
                'guest_phone' => $booking->guest_phone,
                'bank_name' => $tenant->paystack_bank_name,
                'bank_account_name' => $tenant->paystack_account_name,
                'bank_account_number' => $tenant->paystack_account_number,
                'notes' => $notes,
                'subtotal' => $subtotal,
                'amount_paid' => max(0, $initialAmountPaid),
                'total' => $subtotal,
                'issued_at' => $issue ? $now : null,
                'due_at' => $dueAt,
            ]);

            foreach ($normalisedLines as $row) {
                InvoiceLine::query()->create([
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoice->id,
                ] + $row);
            }

            activity('invoice')
                ->causedBy($operator)
                ->performedOn($invoice)
                ->event($issue ? 'invoice_issued' : 'invoice_drafted')
                ->withProperties([
                    'number' => $number,
                    'total' => $subtotal,
                    'lines' => count($normalisedLines),
                    'booking' => $booking->booking_ref,
                ])
                ->log("Operator {$operator->name} ".($issue ? 'issued' : 'drafted').' invoice '.($number ?? "#{$invoice->id}")." for booking {$booking->booking_ref}.");

            return $invoice->load('lines');
        });
    }
}
