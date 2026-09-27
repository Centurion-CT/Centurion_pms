<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Corporate;
use App\Models\Invoice;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates ONE consolidated invoice covering every stay a corporate's
 * staff had between two dates. What corp AP teams actually want each
 * month: one big invoice with a line per stay + a line per unsettled
 * charge + the corp discount surfaced, not twenty separate small
 * invoices.
 *
 * Selection rules:
 *   - booking.corporate_id = $corporate->id
 *   - booking.checkout_date within [dateFrom, dateTo]
 *   - booking is NOT already covered by an Issued/Paid invoice (checked
 *     via existing Invoice rows). Drafts are treated as "not yet
 *     committed" so a stale draft won't block a fresh consolidation.
 *
 * Line synthesis per stay:
 *   - Room accommodation line: "<guest> · <checkin> → <checkout>",
 *     qty=nights, unit_price=room_total/nights.
 *   - One line per unsettled BookingCharge on the stay.
 *   - Corporate rate discount line (negative) when the booking carries
 *     a stamped discount amount.
 *
 * Invoice.booking_id is left NULL — the invoice belongs to the corp
 * as a whole, not any single stay. Per-line source_type/source_id
 * still point back at the individual bookings + charges so the audit
 * trail is intact.
 */
class CreateConsolidatedCorporateInvoice
{
    public function handle(
        Tenant $tenant,
        Corporate $corporate,
        Operator $operator,
        Carbon $dateFrom,
        Carbon $dateTo,
        ?string $notes = null,
        ?Carbon $dueAt = null,
        bool $issue = true,
    ): Invoice {
        $bookings = $this->eligibleBookings($corporate, $dateFrom, $dateTo);
        if ($bookings->isEmpty()) {
            throw new RuntimeException(
                'No uninvoiced stays for this corporate in the picked window.',
            );
        }

        return DB::transaction(function () use ($tenant, $corporate, $operator, $bookings, $notes, $dueAt, $issue): Invoice {
            $now = Carbon::now();
            $lines = $this->buildLines($bookings);
            $subtotal = array_sum(array_column($lines, 'line_total'));

            $sequence = null;
            $number = null;
            if ($issue) {
                $currentMax = (int) Invoice::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereNotNull('sequence')
                    ->lockForUpdate()
                    ->max('sequence');
                $sequence = $currentMax + 1;
                $number = sprintf('INV-%d-%06d', (int) $now->year, $sequence);
            }

            $invoice = Invoice::query()->create([
                'tenant_id' => $tenant->id,
                'booking_id' => null,
                'customer_id' => null,
                'corporate_id' => $corporate->id,
                'issued_by_operator_id' => $operator->id,
                'sequence' => $sequence,
                'number' => $number,
                'status' => $issue ? InvoiceStatus::Issued : InvoiceStatus::Draft,
                // Billing party info — the invoice paper's "Bill to" cell
                // reads from booking.corporate first, so setting these
                // to the corp keeps every non-branch downstream (email
                // reports, exports) coherent.
                'guest_name' => $corporate->name,
                'guest_email' => $corporate->billing_email ?? $corporate->primary_email,
                'guest_phone' => $corporate->primary_phone,
                'bank_name' => $tenant->paystack_bank_name,
                'bank_account_name' => $tenant->paystack_account_name,
                'bank_account_number' => $tenant->paystack_account_number,
                'notes' => $notes,
                'subtotal' => $subtotal,
                'amount_paid' => 0,
                'total' => $subtotal,
                'issued_at' => $issue ? $now : null,
                'due_at' => $dueAt,
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            return $invoice->refresh();
        });
    }

    /**
     * Every corp booking whose checkout falls in the window AND that
     * doesn't already have an Issued/Paid invoice attached.
     *
     * @return \Illuminate\Support\Collection<int, Booking>
     */
    private function eligibleBookings(Corporate $corporate, Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        return Booking::query()
            ->with(['room', 'charges'])
            ->where('corporate_id', $corporate->id)
            ->whereDate('checkout_date', '>=', $from->toDateString())
            ->whereDate('checkout_date', '<=', $to->toDateString())
            ->whereDoesntHave('invoices', function ($q): void {
                $q->whereIn('status', [
                    InvoiceStatus::Issued->value,
                    InvoiceStatus::Partial->value,
                    InvoiceStatus::Paid->value,
                ]);
            })
            ->orderBy('checkout_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Booking>  $bookings
     * @return list<array<string, mixed>>
     */
    private function buildLines(\Illuminate\Support\Collection $bookings): array
    {
        $lines = [];
        foreach ($bookings as $booking) {
            /** @var Booking $booking */
            $nights = max(1, (int) $booking->nights);
            $roomTotal = (int) $booking->room_total;
            $unitPrice = intdiv($roomTotal, $nights);

            $lines[] = [
                'line_type'   => InvoiceLineType::Night->value,
                'source_type' => Booking::class,
                'source_id'   => $booking->id,
                'description' => sprintf(
                    '%s · %s → %s · %s',
                    (string) $booking->guest_name,
                    $booking->checkin_date?->toDateString() ?? '?',
                    $booking->checkout_date?->toDateString() ?? '?',
                    (string) ($booking->room?->name ?? 'Room'),
                ),
                'quantity'    => $nights,
                'unit_price'  => $unitPrice,
                'line_total'  => $roomTotal,
            ];

            foreach ($booking->charges->where('settled', false) as $charge) {
                /** @var BookingCharge $charge */
                $lines[] = [
                    'line_type'   => InvoiceLineType::Charge->value,
                    'source_type' => BookingCharge::class,
                    'source_id'   => $charge->id,
                    'description' => sprintf(
                        '%s · %s',
                        (string) $charge->description,
                        (string) $booking->guest_name,
                    ),
                    'quantity'    => 1,
                    'unit_price'  => (int) $charge->amount,
                    'line_total'  => (int) $charge->amount,
                ];
            }

            $corpDiscount = (int) ($booking->corporate_discount_naira ?? 0);
            if ($corpDiscount > 0) {
                $lines[] = [
                    'line_type'   => InvoiceLineType::Custom->value,
                    'source_type' => Booking::class,
                    'source_id'   => $booking->id,
                    'description' => sprintf('Corporate rate · %s', (string) $booking->guest_name),
                    'quantity'    => 1,
                    'unit_price'  => -$corpDiscount,
                    'line_total'  => -$corpDiscount,
                ];
            }
        }
        return $lines;
    }
}
