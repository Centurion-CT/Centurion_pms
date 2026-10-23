<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Spend from a guest wallet to settle (or partially settle) a booking's
 * outstanding balance. Creates a Payment row (method=wallet) so the
 * regular reports + payment_status projection see the money, and a
 * CustomerWalletTransaction so the wallet ledger stays in sync.
 *
 * `$operator` is optional — the guest-portal Show component calls this
 * from a token-authenticated request where no Operator exists. The
 * activity log then attributes the spend to the guest instead.
 *
 * `$amount` is optional — omit it and the action pays the full
 * outstanding, capped at whatever the wallet holds.
 */
class PayBookingFromWallet
{
    public function __construct(private readonly WalletService $wallet) {}

    public function handle(
        Booking $booking,
        ?Operator $operator = null,
        ?int $amount = null,
    ): ?Payment {
        $customer = $booking->customer;
        if (! $customer instanceof Customer) {
            throw new InvalidArgumentException('Booking has no linked customer — cannot pay from wallet.');
        }

        $outstanding = (int) $booking->outstandingTotal();
        if ($outstanding <= 0) {
            return null;
        }

        $walletBalance = $customer->walletBalance();
        if ($walletBalance <= 0) {
            throw new RuntimeException('Wallet is empty — top up before paying from wallet.');
        }

        $requested = $amount ?? $outstanding;
        if ($requested <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $toSpend = min($requested, $outstanding, $walletBalance);

        return DB::transaction(function () use ($booking, $customer, $operator, $toSpend, $outstanding): Payment {
            $alreadyPaid = (int) Payment::query()
                ->where('booking_id', $booking->id)
                ->where('status', PaymentStatus::Succeeded)
                ->sum('amount');

            $refNote = $operator !== null
                ? sprintf('Paid from wallet by %s', $operator->name)
                : sprintf('Paid from wallet by guest %s', $customer->name);

            $payment = Payment::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'invoice_id' => null,
                'paystack_reference' => 'wallet-'.Str::ulid()->toBase32(),
                'amount' => $toSpend,
                'type' => $alreadyPaid > 0 ? PaymentType::Balance : PaymentType::Full,
                'method' => 'wallet',
                'reference_note' => $refNote,
                'status' => PaymentStatus::Succeeded,
                'paid_at' => Carbon::now(),
            ]);

            $this->wallet->spend(
                customer: $customer,
                amount: $toSpend,
                description: sprintf('Paid ₦%s toward booking %s', number_format($toSpend), $booking->booking_ref),
                meta: ['source' => 'pay_booking', 'booking_ref' => $booking->booking_ref],
                links: ['booking_id' => $booking->id, 'payment_id' => $payment->id],
                causer: $operator ?? $customer,
            );

            // Apply the spend to what is owed so the outstanding drops
            // by exactly the amount taken: balance_due first, then
            // unsettled charges oldest-first (same order as
            // RecordPartPayment / SettleChargesWalletFirst). Before this,
            // a PARTIAL wallet payment wrote the Payment row + debited
            // the wallet but left balance_due untouched, so the guest
            // still "owed" the full amount (docs/host-app/PHASE-2.md).
            $this->applyToOwed($booking, $toSpend, $operator);

            // If we fully cleared the outstanding, flip payment_status
            // + drop balance_due so downstream reports see it settled.
            if ($toSpend >= $outstanding) {
                $booking->forceFill([
                    'payment_status' => PaymentStatus::Succeeded,
                    'balance_due' => 0,
                ])->save();
            }

            $log = activity('booking')
                ->performedOn($booking)
                ->event('wallet_payment_recorded')
                ->withProperties([
                    'booking_ref' => $booking->booking_ref,
                    'amount' => $toSpend,
                    'payment_id' => $payment->id,
                    'via' => $operator !== null ? 'operator' : 'guest',
                ]);

            if ($operator !== null) {
                $log->causedBy($operator);
                $log->log(sprintf(
                    'Operator %s paid ₦%s from %s\'s wallet toward %s.',
                    $operator->name,
                    number_format($toSpend),
                    $customer->name,
                    $booking->booking_ref,
                ));
            } else {
                $log->log(sprintf(
                    'Guest %s paid ₦%s from wallet toward %s via portal.',
                    $customer->name,
                    number_format($toSpend),
                    $booking->booking_ref,
                ));
            }

            return $payment->refresh();
        });
    }
    /**
     * Reduce balance_due, then settle unsettled charges oldest-first
     * (settled_method = wallet). A charge the remaining amount only
     * partly covers is split: the row shrinks to the covered part and
     * is settled, a sibling row carries the remainder unsettled, so a
     * charge row only ever carries one payment method (same convention
     * as {@see SettleChargesWalletFirst}).
     */
    private function applyToOwed(Booking $booking, int $amount, ?Operator $operator): void
    {
        $booking->refresh();
        $remaining = $amount;

        $balance = (int) $booking->balance_due;
        $toBalance = min($remaining, $balance);
        if ($toBalance > 0) {
            $booking->forceFill(['balance_due' => $balance - $toBalance])->save();
            $remaining -= $toBalance;
        }
        if ($remaining <= 0) {
            return;
        }

        $now = Carbon::now();
        $charges = BookingCharge::query()
            ->where('booking_id', $booking->id)
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
                    'settled' => true,
                    'settled_at' => $now,
                    'settled_method' => 'wallet',
                ])->save();
                $remaining -= $chargeAmount;

                continue;
            }

            BookingCharge::query()->create([
                'tenant_id' => $charge->tenant_id,
                'booking_id' => $charge->booking_id,
                'created_by_operator_id' => $operator?->getKey() ?? $charge->created_by_operator_id,
                'type' => $charge->type,
                'description' => $charge->description,
                'amount' => $chargeAmount - $remaining,
                'settled' => false,
            ]);
            $charge->forceFill([
                'amount' => $remaining,
                'settled' => true,
                'settled_at' => $now,
                'settled_method' => 'wallet',
            ])->save();
            $remaining = 0;
        }
    }
}
