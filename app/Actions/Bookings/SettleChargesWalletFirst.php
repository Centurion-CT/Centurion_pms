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
 * Settle every unsettled charge by draining the guest wallet first and
 * collecting whatever's left in whatever method the operator picked
 * (cash, transfer, card, etc.). Matches the front-desk mental model —
 * "use the wallet, guest pays the shortfall at the counter."
 *
 * When the wallet balance falls between charges we split the next
 * charge into two rows: one for the residual wallet amount (marked
 * settled from wallet) and one for the remainder (which the fallback
 * sweep then settles). Charge-level rows stay honest — a single
 * "POS · Rooftop Bar" line only ever carries one payment method.
 *
 * The "wallet-only" option in the UI stays wired to
 * {@see SettleChargesFromWallet}; this action is for the mixed path.
 */
class SettleChargesWalletFirst
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly SettleCharges $fallback,
    ) {}

    /**
     * @return array{wallet:int, wallet_cleared:int, fallback_cleared:int}
     */
    public function handle(Booking $booking, Operator $operator, string $fallbackMethod): array
    {
        return DB::transaction(function () use ($booking, $operator, $fallbackMethod): array {
            $walletUsed = 0;
            $walletCleared = 0;

            $customer = $booking->customer;
            if ($customer instanceof Customer && (int) $customer->walletBalance() > 0) {
                // Drain wallet against the room balance first (matches
                // RecordPartPayment's application order). Otherwise a
                // guest whose wallet has some balance would see the
                // wallet skip past balance_due and only chip at extra
                // charges, then fallback would cover balance_due via
                // the picked method — misleading tally.
                $balanceDue = (int) $booking->fresh()->balance_due;
                if ($balanceDue > 0) {
                    $available = (int) ($customer->fresh()?->walletBalance() ?? 0);
                    $walletPortion = min($available, $balanceDue);
                    if ($walletPortion > 0) {
                        try {
                            $this->wallet->spend(
                                customer: $customer,
                                amount: $walletPortion,
                                description: sprintf('Room balance on %s', $booking->booking_ref),
                                meta: ['source' => 'settle_charges', 'booking_ref' => $booking->booking_ref, 'target' => 'balance_due'],
                                links: ['booking_id' => $booking->id],
                                causer: $operator,
                            );
                            $booking->forceFill(['balance_due' => $balanceDue - $walletPortion])->save();
                            $walletUsed += $walletPortion;
                            // Balance settlement isn't a "charge cleared"
                            // but counting it as one keeps the surface
                            // total honest for the notify string.
                            $walletCleared++;
                        } catch (RuntimeException) {
                            // Wallet spend blocked (e.g. concurrent
                            // debit), skip the balance-due leg and let
                            // the fallback method handle it.
                        }
                    }
                }

                $charges = BookingCharge::query()
                    ->where('booking_id', $booking->id)
                    ->where('settled', false)
                    ->orderBy('id')
                    ->get();

                foreach ($charges as $charge) {
                    $available = (int) ($customer->fresh()?->walletBalance() ?? 0);
                    if ($available <= 0) {
                        break;
                    }

                    if ($available >= (int) $charge->amount) {
                        // Wallet covers this whole charge.
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

                        $walletUsed += (int) $charge->amount;
                        $walletCleared++;

                        $charge->forceFill([
                            'settled' => true,
                            'settled_at' => Carbon::now(),
                            'settled_method' => 'wallet',
                        ])->save();

                        continue;
                    }

                    // Wallet has some balance but not enough for the whole
                    // charge. Split: shrink this row to the wallet portion
                    // and settle it, create a sibling row for the remainder
                    // that the fallback sweep will then pick up.
                    $walletPortion = $available;
                    $remainder = (int) $charge->amount - $walletPortion;

                    try {
                        $this->wallet->spend(
                            customer: $customer,
                            amount: $walletPortion,
                            description: sprintf(
                                '%s partial charge on %s — %s',
                                $charge->type->label(),
                                $booking->booking_ref,
                                $charge->description,
                            ),
                            meta: ['source' => 'settle_charges', 'booking_ref' => $booking->booking_ref, 'split' => 'wallet'],
                            links: ['booking_id' => $booking->id, 'booking_charge_id' => $charge->id],
                            causer: $operator,
                        );
                    } catch (RuntimeException) {
                        break;
                    }

                    $originalDescription = (string) $charge->description;
                    $originalType = $charge->type;

                    $charge->forceFill([
                        'amount' => $walletPortion,
                        'settled' => true,
                        'settled_at' => Carbon::now(),
                        'settled_method' => 'wallet',
                    ])->save();
                    $walletUsed += $walletPortion;
                    $walletCleared++;

                    BookingCharge::query()->create([
                        'tenant_id' => $charge->tenant_id,
                        'booking_id' => $charge->booking_id,
                        'created_by_operator_id' => $operator->getKey(),
                        'type' => $originalType,
                        'description' => $originalDescription,
                        'amount' => $remainder,
                        'settled' => false,
                    ]);

                    // Wallet is now empty by definition — nothing else
                    // to try here.
                    break;
                }
            }

            $fallbackCleared = $this->fallback->settleAll($booking, $operator, $fallbackMethod);

            if ($walletCleared > 0) {
                activity('booking')
                    ->causedBy($operator)
                    ->performedOn($booking)
                    ->event('charges_settled_wallet_first')
                    ->withProperties([
                        'wallet_amount' => $walletUsed,
                        'wallet_cleared' => $walletCleared,
                        'fallback_cleared' => $fallbackCleared,
                        'fallback_method' => $fallbackMethod,
                    ])
                    ->log(sprintf(
                        'Operator %s settled charges on %s: ₦%s from wallet + %d via %s.',
                        $operator->name,
                        $booking->booking_ref,
                        number_format($walletUsed),
                        $fallbackCleared,
                        $fallbackMethod,
                    ));
            }

            return [
                'wallet' => $walletUsed,
                'wallet_cleared' => $walletCleared,
                'fallback_cleared' => $fallbackCleared,
            ];
        });
    }
}
