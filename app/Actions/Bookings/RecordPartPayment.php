<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Records a "part payment" against a booking whose outstanding
 * balance is made up of some mix of balance_due + unsettled
 * BookingCharges. Extracted so both the reservation Show page + the
 * dashboard's Outstanding-balances panel run identical accounting.
 *
 * Application order (so outstandingTotal reduces by exactly the paid
 * amount, no more, no less):
 *   1. Payment row on the general ledger — visible on /payments. We
 *      bypass RecordManualPayment because its idempotency guard
 *      compares alreadyPaid against total_amount, which never moves
 *      for BookingCharge lines (extension, F&B, overstay all live
 *      off-total). Without this bypass, part payments on a fully-paid
 *      base stay would silently drop.
 *   2. balance_due absorbs first, floors at 0.
 *   3. Remainder chips unsettled charges oldest-first: full-settles
 *      when the payment covers the full charge, otherwise trims
 *      charge.amount + stamps a "part-paid ₦N of ₦M originally"
 *      trail in the description so the row explains itself. If the
 *      charge was already trimmed by an earlier part payment we walk
 *      back to the true original so the trail cumulates instead of
 *      drifting.
 */
class RecordPartPayment
{
    /**
     * @param  'cash'|'transfer'|'card'|'pos'|'other'  $method
     */
    public function handle(
        Booking $booking,
        Operator $operator,
        int $amount,
        string $method,
        ?string $referenceNote = null,
    ): void {
        $outstanding = $booking->outstandingTotal();
        if ($outstanding <= 0) {
            throw new RuntimeException('Nothing outstanding to collect.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount above ₦0.');
        }
        if ($amount > $outstanding) {
            throw new RuntimeException(sprintf(
                'Amount ₦%s is more than outstanding ₦%s. Use Settle everything instead.',
                number_format($amount),
                number_format($outstanding),
            ));
        }
        if (! in_array($method, ['cash', 'transfer', 'card', 'pos', 'other'], true)) {
            throw new RuntimeException('Pick a payment method.');
        }

        $referenceNote = $referenceNote !== null && trim($referenceNote) !== ''
            ? trim($referenceNote)
            : 'Part payment on '.$booking->booking_ref;

        DB::transaction(function () use ($booking, $operator, $amount, $method, $referenceNote): void {
            Payment::query()->create([
                'tenant_id'                => $booking->tenant_id,
                'booking_id'               => $booking->id,
                'invoice_id'               => null,
                'paystack_reference'       => 'part-'.Str::ulid()->toBase32(),
                'amount'                   => $amount,
                'type'                     => PaymentType::Extra,
                'method'                   => $method,
                'collected_by_operator_id' => $operator->getKey(),
                'reference_note'           => Str::limit($referenceNote, 117),
                'status'                   => PaymentStatus::Succeeded,
                'paid_at'                  => Carbon::now(),
            ]);

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('part_payment_recorded')
                ->withProperties([
                    'booking_ref' => $booking->booking_ref,
                    'amount'      => $amount,
                    'method'      => $method,
                ])
                ->log(sprintf(
                    'Part payment of ₦%s via %s recorded against booking %s.',
                    number_format($amount),
                    $method,
                    $booking->booking_ref,
                ));

            $booking = $booking->refresh();

            $balance = (int) $booking->balance_due;
            $toBalance = min($amount, $balance);
            if ($toBalance > 0) {
                $booking->forceFill(['balance_due' => $balance - $toBalance])->save();
            }
            $remaining = $amount - $toBalance;

            if ($remaining <= 0) {
                return;
            }

            $now = Carbon::now();
            $charges = $booking->charges()
                ->where('settled', false)
                ->orderBy('id')
                ->get();
            foreach ($charges as $charge) {
                if ($remaining <= 0) {
                    break;
                }
                $chargeAmount = (int) $charge->amount;
                if ($chargeAmount <= 0) {
                    continue;
                }
                if ($remaining >= $chargeAmount) {
                    $charge->forceFill([
                        'settled'        => true,
                        'settled_at'     => $now,
                        'settled_method' => 'part_payment',
                    ])->save();
                    $remaining -= $chargeAmount;
                    continue;
                }

                $existing = (string) $charge->description;
                $stripped = preg_replace(
                    '/\s*·\s*part-paid[^·]*(₦[\d,]+\s+received[^·]*)?/u',
                    '', $existing,
                );
                $originalCharge = preg_match('/₦([\d,]+)\s+originally/u', $existing, $m)
                    ? (int) str_replace(',', '', $m[1])
                    : $chargeAmount;
                $newAmount = $chargeAmount - $remaining;
                $received = $originalCharge - $newAmount;
                $charge->forceFill([
                    'amount'      => $newAmount,
                    'description' => sprintf(
                        '%s · part-paid ₦%s of ₦%s originally',
                        trim((string) $stripped),
                        number_format($received),
                        number_format($originalCharge),
                    ),
                ])->save();
                $remaining = 0;
            }
        });
    }
}
