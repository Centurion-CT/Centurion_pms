<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Operator;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sweep every unsettled charge on a booking, paying each from the
 * guest wallet in id order until the wallet is empty. Whatever the
 * wallet can't cover stays unsettled — the operator still has to
 * collect that in cash before {@see MarkCheckedOut} lets the guest go.
 *
 * Returns the number of charges cleared.
 */
class SettleChargesFromWallet
{
    public function __construct(private readonly WalletService $wallet) {}

    public function handle(Booking $booking, Operator $operator): int
    {
        $customer = $booking->customer;
        if (! $customer instanceof Customer) {
            return 0;
        }

        return DB::transaction(function () use ($booking, $customer, $operator): int {
            $charges = BookingCharge::query()
                ->where('booking_id', $booking->id)
                ->where('settled', false)
                ->orderBy('id')
                ->get();

            $settled = 0;
            foreach ($charges as $charge) {
                $available = (int) ($customer->fresh()?->walletBalance() ?? 0);
                if ($available < $charge->amount) {
                    // Not enough to cover this charge — stop rather
                    // than partial-pay, keeps the ledger tidy.
                    break;
                }

                try {
                    $this->wallet->spend(
                        customer: $customer,
                        amount: (int) $charge->amount,
                        description: sprintf(
                            '%s charge on %s — %s',
                            $charge->type->label(),
                            $booking->booking_ref,
                            $charge->description,
                        ),
                        meta: ['source' => 'settle_charges', 'booking_ref' => $booking->booking_ref],
                        links: ['booking_id' => $booking->id, 'booking_charge_id' => $charge->id],
                        causer: $operator,
                    );
                } catch (RuntimeException) {
                    break;
                }

                $charge->forceFill([
                    'settled' => true,
                    'settled_at' => Carbon::now(),
                    'settled_method' => 'wallet',
                ])->save();

                $settled++;
            }

            if ($settled > 0) {
                activity('booking')
                    ->causedBy($operator)
                    ->performedOn($booking)
                    ->event('charges_settled_from_wallet')
                    ->log(sprintf(
                        'Operator %s settled %d charge(s) on %s from %s\'s wallet.',
                        $operator->name,
                        $settled,
                        $booking->booking_ref,
                        $customer->name,
                    ));
            }

            return $settled;
        });
    }

    /**
     * Pay one specific charge from the guest wallet. Returns true when
     * the wallet covered it (charge marked settled with method 'wallet'),
     * false when the customer has no wallet or the balance is short —
     * caller decides how to surface that to the operator.
     */
    public function handleOne(BookingCharge $charge, Operator $operator): bool
    {
        if ($charge->settled) {
            return false;
        }
        $booking = $charge->booking;
        $customer = $booking?->customer;
        if (! $customer instanceof Customer) {
            return false;
        }

        return DB::transaction(function () use ($charge, $booking, $customer, $operator): bool {
            $available = (int) ($customer->fresh()?->walletBalance() ?? 0);
            if ($available < (int) $charge->amount) {
                return false;
            }

            try {
                $this->wallet->spend(
                    customer: $customer,
                    amount: (int) $charge->amount,
                    description: sprintf(
                        '%s charge on %s — %s',
                        $charge->type->label(),
                        $booking->booking_ref,
                        $charge->description,
                    ),
                    meta: ['source' => 'settle_charges', 'booking_ref' => $booking->booking_ref],
                    links: ['booking_id' => $booking->id, 'booking_charge_id' => $charge->id],
                    causer: $operator,
                );
            } catch (RuntimeException) {
                return false;
            }

            $charge->forceFill([
                'settled' => true,
                'settled_at' => Carbon::now(),
                'settled_method' => 'wallet',
            ])->save();

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('charge_settled_from_wallet')
                ->log(sprintf(
                    'Operator %s settled 1 charge on %s from %s\'s wallet.',
                    $operator->name,
                    $booking->booking_ref,
                    $customer->name,
                ));

            return true;
        });
    }
}
