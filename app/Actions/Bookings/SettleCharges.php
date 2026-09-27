<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mark one or more unsettled charges as paid. Used by the front-desk
 * console's "Mark paid" buttons + the express "Settle everything" flow.
 *
 * Money is assumed to have changed hands off-platform (cash at the
 * front desk, transfer, manual card terminal). The settled_method
 * string is what the operator picked from the UI dropdown.
 *
 * For every charge we settle we ALSO write a Payment row (type=Extra)
 * so the money shows up on the Payments page + rolls into Finance's
 * "Collected" total. Prior to this, "Mark paid" only flipped
 * charge.settled=true and left the payments ledger blind - operators
 * would extend a guest, settle the extension charge as cash, and then
 * find no receipt on the payments list. Wallet-based settlement has
 * its own path via {@see SettleChargesFromWallet}, which already
 * writes a Payment row directly.
 */
class SettleCharges
{
    /**
     * Settle every still-unsettled part of the booking — including the
     * unpaid room balance (booking.balance_due), not just extra charges.
     *
     * Prior to this the action only iterated unsettled BookingCharges,
     * so a booking with balance_due > 0 but no unsettled charges (e.g.
     * front desk billed an overstay before the guest cleared the base
     * stay) had a "Settle everything · ₦N" button that fired the action,
     * touched nothing, and left the guest stuck at the outstanding tile
     * — same number, same block on checkout.
     *
     * Order matches RecordPartPayment: balance_due first (Payment
     * type=Balance), then unsettled charges oldest-first (type=Extra).
     *
     * Return value is the number of ledger movements — balance-due
     * clear counts as 1 if it fired, plus one per settled charge.
     */
    public function settleAll(Booking $booking, Operator $operator, string $method): int
    {
        return DB::transaction(function () use ($booking, $operator, $method): int {
            $booking = $booking->fresh() ?? $booking;
            $now = Carbon::now();
            $cleared = 0;

            // 1. Room balance first. This is where overstay-only-billed
            //    stays used to fall through the cracks.
            $balanceDue = (int) $booking->balance_due;
            if ($balanceDue > 0) {
                Payment::query()->create([
                    'tenant_id'                => $booking->tenant_id,
                    'booking_id'               => $booking->id,
                    'invoice_id'               => null,
                    'paystack_reference'       => 'balance-'.$booking->id.'-'.Str::ulid()->toBase32(),
                    'amount'                   => $balanceDue,
                    'type'                     => PaymentType::Balance,
                    'method'                   => $method,
                    'collected_by_operator_id' => $operator->getKey(),
                    'reference_note'           => Str::limit('Balance settled on '.$booking->booking_ref, 117),
                    'status'                   => PaymentStatus::Succeeded,
                    'paid_at'                  => $now,
                ]);
                $booking->forceFill(['balance_due' => 0])->save();
                $cleared++;
            }

            // 2. Then unsettled charges. Pull rows first so we can
            //    create matching Payment ledger entries per charge.
            $charges = BookingCharge::query()
                ->where('booking_id', $booking->id)
                ->where('settled', false)
                ->get();

            foreach ($charges as $charge) {
                $charge->forceFill([
                    'settled'        => true,
                    'settled_at'     => $now,
                    'settled_method' => $method,
                ])->save();
                $this->recordPayment($charge, $booking, $operator, $method, $now);
            }
            $cleared += $charges->count();

            if ($cleared > 0) {
                activity('booking')
                    ->causedBy($operator)
                    ->performedOn($booking)
                    ->event('charges_settled')
                    ->withProperties([
                        'balance_settled' => $balanceDue,
                        'charges_settled' => $charges->count(),
                        'method' => $method,
                    ])
                    ->log(sprintf(
                        'Operator %s settled %s on %s via %s.',
                        $operator->name,
                        $balanceDue > 0
                            ? sprintf('₦%s balance + %d charge(s)', number_format($balanceDue), $charges->count())
                            : sprintf('%d charge(s)', $charges->count()),
                        $booking->booking_ref,
                        $method,
                    ));
            }

            return $cleared;
        });
    }

    /**
     * Settle a single charge (operator clicks "Mark paid" on one row).
     */
    public function settleOne(BookingCharge $charge, Operator $operator, string $method): BookingCharge
    {
        if ($charge->settled) {
            return $charge;
        }

        return DB::transaction(function () use ($charge, $operator, $method): BookingCharge {
            $now = Carbon::now();
            $charge->forceFill([
                'settled'        => true,
                'settled_at'     => $now,
                'settled_method' => $method,
            ])->save();

            $booking = $charge->booking()->first();
            if ($booking !== null) {
                $this->recordPayment($charge, $booking, $operator, $method, $now);
            }

            activity('booking_charge')
                ->causedBy($operator)
                ->performedOn($charge)
                ->event('settled')
                ->log(sprintf(
                    'Operator %s marked charge "%s" (₦%s) as paid via %s.',
                    $operator->name,
                    $charge->description,
                    number_format($charge->amount),
                    $method,
                ));

            return $charge->refresh();
        });
    }

    /**
     * Write a Payment row for one settled charge so the money lands
     * on the payments ledger. Type is Extra (matches other non-room
     * charges - overstay, room service, damage, ...) and the amount
     * mirrors the charge exactly. Idempotent via the synthetic
     * paystack_reference which is unique.
     */
    private function recordPayment(
        BookingCharge $charge,
        Booking $booking,
        Operator $operator,
        string $method,
        Carbon $paidAt,
    ): void {
        if ((int) $charge->amount <= 0) {
            return;
        }

        Payment::query()->create([
            'tenant_id'               => $booking->tenant_id,
            'booking_id'              => $booking->id,
            'invoice_id'              => null,
            'paystack_reference'      => 'charge-'.$charge->id.'-'.Str::ulid()->toBase32(),
            'amount'                  => (int) $charge->amount,
            'type'                    => PaymentType::Extra,
            'method'                  => $method,
            'collected_by_operator_id'=> $operator->getKey(),
            // reference_note column is varchar(120); the "Settled: "
            // prefix eats 9 chars, so we cap the description at 111
            // to keep the total inside the column. Charge descriptions
            // can grow long (extension + overstay credit + discount
            // trail all end up on one line), which was tripping the
            // "value too long for type character varying(120)" error.
            'reference_note'          => \Illuminate\Support\Str::limit('Settled: '.$charge->description, 117),
            'status'                  => PaymentStatus::Succeeded,
            'paid_at'                 => $paidAt,
        ]);
    }
}
