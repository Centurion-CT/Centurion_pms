<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
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
}
