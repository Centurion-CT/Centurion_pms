<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Actions\Bookings\PayBookingFromWallet;
use App\Actions\Bookings\RecordManualPayment;
use App\Actions\Bookings\RecordPartPayment;
use App\Actions\Bookings\SettleChargesWalletFirst;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Operator;
use App\Services\Customers\CustomerLinker;
use App\Services\Customers\WalletService;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One payment layer for the web dashboard AND the host app
 * (docs/host-app/PHASE-2.md, section A). It only composes the existing
 * booking money actions; it does not invent new money rules:
 *
 *   take()                 amount < outstanding  -> RecordPartPayment
 *                          amount >= outstanding -> SettleChargesWalletFirst
 *                          method = wallet       -> PayBookingFromWallet
 *   confirmAndPay()        Pending -> Confirmed (OnHold if partial) + payment
 *   collectForExtension()  the tail of the extend-stay flow
 *   settleOnCreate()       the post-create tail of every "new booking" surface
 *
 * Every write busts the dashboard snapshot and trims the reference note
 * to the payments.reference_note column (varchar 120).
 */
class BookingPayments
{
    public const NOTE_MAX = 120;

    /** Statuses a new booking can be created with (web + app). */
    public const CREATE_STATUSES = ['confirmed', 'on_hold', 'pending', 'wallet', 'corp_invoice'];

    /**
     * Result of the last settle-everything leg run by take() (the
     * SettleChargesWalletFirst tally), so callers can word their
     * confirmation. Null when take() took a part or wallet payment.
     *
     * @var array{wallet:int, wallet_cleared:int, fallback_cleared:int}|null
     */
    public ?array $lastSettle = null;

    public function __construct(
        private readonly RecordPartPayment $partPayment,
        private readonly SettleChargesWalletFirst $settleWalletFirst,
        private readonly PayBookingFromWallet $walletPay,
        private readonly RecordManualPayment $manualPayment,
        private readonly WalletService $wallet,
    ) {}

    /**
     * Take money against a booking's outstanding (balance_due +
     * unsettled charges + not-yet-billed overstay).
     *
     *  - amount < outstanding: part payment, reduces balance_due then
     *    charges oldest-first by exactly `amount`.
     *  - amount >= outstanding: settle everything, wallet first, the rest
     *    via `method`. Any surplus over the outstanding is credited to
     *    the guest wallet (as RecordManualPayment always did).
     *  - asPartPayment: an amount equal to the outstanding is still
     *    recorded as one part payment (the web part-payment forms).
     *  - method = wallet: spend the wallet up to `amount` (capped at the
     *    wallet balance and the outstanding), reducing what is owed.
     *
     * @return int the new outstanding
     */
    public function take(
        Booking $booking,
        Operator $operator,
        int $amount,
        string $method,
        ?string $note = null,
        bool $asPartPayment = false,
        bool $walletFirst = false,
    ): int {
        $this->assertTakeMethod($method);
        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount above ₦0.');
        }
        $note = self::trimNote($note);
        $this->lastSettle = null;

        DB::transaction(function () use ($booking, $operator, $amount, $method, $note, $asPartPayment, $walletFirst): void {
            $booking->refresh();
            $outstanding = $booking->outstandingTotal();
            if ($outstanding <= 0) {
                throw new RuntimeException('Nothing outstanding to collect.');
            }

            if ($method === PaymentMethod::Wallet->value) {
                $customer = $booking->customer;
                if (! $customer instanceof Customer || $customer->walletBalance() <= 0) {
                    throw new RuntimeException('The guest has no wallet balance to spend.');
                }
                $this->walletPay->handle($booking, $operator, $amount);

                return;
            }

            // The web part-payment forms record an amount that equals
            // the outstanding as one part payment too (asPartPayment),
            // as they always have; anything below it always is one.
            if ($amount < $outstanding || ($asPartPayment && $amount === $outstanding)) {
                $this->partPayment->handle($booking, $operator, $amount, $method, $note);

                return;
            }

            // Bill any accrued-but-unbilled overstay first so "settle
            // everything" clears the same number the outstanding shows.
            self::materialiseUnbilledOverstay($booking, $operator);
            if ($walletFirst) {
                // Web "Settle everything": spend the guest's wallet first,
                // the chosen method covers the rest.
                $this->lastSettle = $this->settleWalletFirst->handle($booking->refresh(), $operator, $method, $note);
            } else {
                // Money actually handed over (app take payment, extend
                // stay, staff app): record it all in the chosen method so
                // the cash drawer reconciles. The wallet is untouched.
                $cleared = app(\App\Actions\Bookings\SettleCharges::class)->settleAll($booking->refresh(), $operator, $method, $note);
                $this->lastSettle = ['wallet' => 0, 'wallet_cleared' => 0, 'fallback_cleared' => $cleared];
            }

            $surplus = $amount - $outstanding;
            $customer = $booking->customer;
            if ($surplus > 0 && $customer instanceof Customer) {
                $this->wallet->topup(
                    customer: $customer,
                    amount: $surplus,
                    description: sprintf('Change from %s payment on booking %s', $method, $booking->booking_ref),
                    paymentMethod: $method,
                    meta: ['source' => 'overpayment_at_checkin', 'booking_ref' => $booking->booking_ref],
                    links: ['booking_id' => $booking->id],
                    causer: $operator,
                );
            }
        });

        DashboardSnapshot::forget();

        return $booking->refresh()->outstandingTotal();
    }

    /**
     * Confirm a Pending booking by taking money for it. Port of the web
     * Reservations Show "Confirm & take payment": status Confirmed when
     * the amount covers what is owed, OnHold (deposit) otherwise; one
     * payment row for what was handed over now (surplus to the wallet);
     * balance_due set to what is still owed.
     *
     * @return int the new outstanding
     */
    public function confirmAndPay(Booking $booking, Operator $operator, int $amount, string $method, ?string $note = null): int
    {
        $booking->refresh();
        if ($booking->booking_status !== BookingStatus::Pending) {
            throw new RuntimeException('Only Pending bookings can be confirmed from here. Use Extend / Add charge for other cases.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Enter how much the guest handed over.');
        }
        $this->assertTakeMethod($method);
        $note = self::trimNote($note);

        DB::transaction(function () use ($booking, $operator, $amount, $method, $note): void {
            $total = (int) $booking->total_amount;
            $alreadyPaid = (int) $booking->payments()
                ->where('status', PaymentStatus::Succeeded)
                ->sum('amount');
            $owed = max(0, $total - $alreadyPaid);

            if ($method === PaymentMethod::Wallet->value) {
                $customer = $booking->customer;
                if (! $customer instanceof Customer || $customer->walletBalance() <= 0) {
                    throw new RuntimeException('The guest has no wallet balance to spend.');
                }
                $booking->forceFill([
                    'balance_due' => $owed,
                    'booking_status' => BookingStatus::OnHold,
                    'payment_status' => PaymentStatus::Succeeded,
                ])->save();
                $this->walletPay->handle($booking->refresh(), $operator, $amount);
                $booking->refresh();
                $booking->forceFill([
                    'booking_status' => $booking->outstandingTotal() <= 0 ? BookingStatus::Confirmed : BookingStatus::OnHold,
                ])->save();

                return;
            }

            $covers = $amount >= $owed;

            // balance_due = total so RecordManualPayment sees money owed;
            // it caps the Payment row at what is still unpaid and routes
            // any surplus to the guest wallet.
            $booking->forceFill([
                'balance_due' => $total,
                'booking_status' => $covers ? BookingStatus::Confirmed : BookingStatus::OnHold,
                'payment_status' => PaymentStatus::Succeeded,
            ])->save();

            // RecordManualPayment's amount is a running total (it no-ops
            // when already-paid >= amount), so pass paid-so-far + now.
            $this->manualPayment->handle(
                $booking->refresh(),
                $operator,
                amount: $alreadyPaid + $amount,
                method: $method,
                referenceNote: $note,
            );

            $booking->refresh()->forceFill([
                'balance_due' => $covers ? 0 : max(0, $owed - $amount),
            ])->save();
        });

        DashboardSnapshot::forget();

        return $booking->refresh()->outstandingTotal();
    }

    /**
     * Collect money right after an extension.
     *
     *  - full:      the whole outstanding via `method`
     *  - extension: the latest unsettled Extension charge via `method`
     *  - wallet:    the wallet, up to the outstanding
     *  - none:      nothing
     *
     * `amount` overrides the derived amount (the web modal lets the
     * operator type one); with mode wallet the method is wallet.
     *
     * @return int the new outstanding
     */
    public function collectForExtension(
        Booking $booking,
        Operator $operator,
        string $mode,
        ?string $method = null,
        ?int $amount = null,
        ?string $note = null,
    ): int {
        if (! in_array($mode, ['full', 'extension', 'wallet', 'none'], true)) {
            throw new RuntimeException('Pick how to collect for the extension.');
        }
        $booking->refresh();
        $outstanding = $booking->outstandingTotal();
        if ($mode === 'none' || $outstanding <= 0) {
            return $outstanding;
        }

        if ($mode === 'wallet') {
            $balance = (int) ($booking->customer?->walletBalance() ?? 0);
            $take = $amount ?? min($balance, $outstanding);
            if ($take <= 0) {
                throw new RuntimeException('The guest has no wallet balance to spend.');
            }

            return $this->take($booking, $operator, $take, PaymentMethod::Wallet->value, $note);
        }

        $method ??= PaymentMethod::Cash->value;
        if ($amount === null) {
            if ($mode === 'full') {
                $amount = $outstanding;
            } else {
                $amount = (int) (BookingCharge::query()
                    ->where('booking_id', $booking->id)
                    ->where('type', BookingChargeType::Extension->value)
                    ->where('settled', false)
                    ->latest('id')
                    ->value('amount') ?? 0);
            }
        }
        if ($amount <= 0) {
            return $outstanding;
        }

        return $this->take($booking, $operator, $amount, $method, $note);
    }

    /**
     * Post-create settlement shared by every new-booking surface (web
     * quick reserve, manual create, calendar sheet, Today sheet, host
     * app). The booking comes straight from CreateBooking /
     * CreateCategoryReservation (Pending + Pending).
     *
     *  - confirmed:    Confirmed + Succeeded, payment for the total
     *  - on_hold:      deposit = what was handed over; Confirmed when it
     *                  covers the total, OnHold otherwise; payment for it
     *                  (surplus to the wallet)
     *  - pending:      Pending + Pending, nothing collected
     *  - wallet:       spend the wallet toward the total; Confirmed when
     *                  it covers it, OnHold otherwise
     *  - corp_invoice: Confirmed + Pending, balance_due = total (billed
     *                  to the company after the stay)
     *
     * `$walletCustomerId` pins the booking to the customer whose wallet
     * the operator saw (phone normalisation can split one guest into two
     * customer rows).
     */
    public function settleOnCreate(
        Booking $booking,
        ?Operator $operator,
        string $status,
        ?int $amount = null,
        ?string $method = null,
        ?int $walletCustomerId = null,
        ?string $note = null,
    ): Booking {
        if (! in_array($status, self::CREATE_STATUSES, true)) {
            throw new RuntimeException('Pick a payment status.');
        }
        $method ??= PaymentMethod::Cash->value;
        if (in_array($status, ['confirmed', 'on_hold'], true) && ! in_array($method, PaymentMethod::deskValues(), true)) {
            throw new RuntimeException('Pick a payment method.');
        }
        $note = self::trimNote($note);

        $booking->refresh();
        $total = (int) $booking->total_amount;

        // Category-only reservations with no price have nothing to settle
        // (matches the category paths' early return).
        if ($booking->room_id === null && $total <= 0 && $status !== 'corp_invoice') {
            return $booking;
        }

        DB::transaction(function () use ($booking, $operator, $status, $amount, $method, $walletCustomerId, $note, $total): void {
            switch ($status) {
                case 'pending':
                    $booking->forceFill([
                        'booking_status' => BookingStatus::Pending,
                        'payment_status' => PaymentStatus::Pending,
                    ])->save();

                    return;

                case 'corp_invoice':
                    $booking->forceFill([
                        'booking_status' => BookingStatus::Confirmed,
                        'payment_status' => PaymentStatus::Pending,
                        'balance_due' => $total,
                    ])->save();

                    return;

                case 'wallet':
                    if ($booking->customer_id === null) {
                        app(CustomerLinker::class)->attachToBooking($booking);
                        $booking->refresh();
                    }
                    if ($walletCustomerId !== null && (int) $booking->customer_id !== $walletCustomerId) {
                        $booking->forceFill(['customer_id' => $walletCustomerId])->save();
                    }
                    $booking->forceFill([
                        'balance_due' => $total,
                        'payment_status' => PaymentStatus::Pending,
                    ])->save();

                    $this->walletPay->handle($booking->refresh(), $operator);

                    $booking->refresh();
                    $booking->forceFill([
                        'booking_status' => $booking->outstandingTotal() <= 0
                            ? BookingStatus::Confirmed
                            : BookingStatus::OnHold,
                    ])->save();

                    return;

                case 'on_hold':
                    $collected = (int) ($amount ?? 0);
                    if ($collected <= 0) {
                        throw new RuntimeException('Enter how much the guest handed over as a deposit.');
                    }
                    $depositBooked = min($collected, $total);
                    $booking->forceFill([
                        'deposit_amount' => $depositBooked,
                        'balance_due' => max(0, $total - $depositBooked),
                        'booking_status' => $collected >= $total ? BookingStatus::Confirmed : BookingStatus::OnHold,
                        'payment_status' => PaymentStatus::Succeeded,
                    ])->save();

                    $this->manualPayment->handle(
                        $booking->refresh(),
                        $operator,
                        amount: $collected,
                        method: $method,
                        referenceNote: $note,
                    );

                    return;

                default: // confirmed
                    $booking->forceFill([
                        'balance_due' => $total,
                        'booking_status' => BookingStatus::Confirmed,
                        'payment_status' => PaymentStatus::Succeeded,
                    ])->save();

                    $this->manualPayment->handle(
                        $booking->refresh(),
                        $operator,
                        amount: $total,
                        method: $method,
                        referenceNote: $note,
                    );

                    $booking->refresh()->forceFill(['balance_due' => 0])->save();
            }
        });

        DashboardSnapshot::forget();

        return $booking->refresh();
    }

    /**
     * What the guest still owes, as the host surfaces show it. Same as
     * Booking::outstandingTotal(), except a Pending booking (created
     * "not paid yet", balance_due left at 0 by CreateBooking) owes its
     * unpaid total.
     */
    public static function owed(Booking $booking): int
    {
        if ($booking->booking_status === BookingStatus::Pending) {
            $paid = (int) $booking->payments()->where('status', PaymentStatus::Succeeded)->sum('amount');
            $charges = (int) $booking->charges()->where('settled', false)->sum('amount');

            return max(0, (int) $booking->total_amount - $paid) + $charges;
        }

        return $booking->outstandingTotal();
    }

    /**
     * Promote accrued-but-unbilled overstay nights to a real Overstay
     * charge (same rule as the Reservations Show "Settle everything").
     */
    public static function materialiseUnbilledOverstay(Booking $booking, Operator $operator): void
    {
        $today = Carbon::today();
        $nights = $booking->unchargedOverstayNights($today);
        if ($nights <= 0) {
            return;
        }
        $baseRate = (int) ($booking->room?->base_rate ?? 0);
        if ($baseRate <= 0) {
            return;
        }
        $rangeStart = $booking->checkout_date->copy()->startOfDay()
            ->addDays($booking->overstayNightsCharged());

        BookingCharge::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'created_by_operator_id' => $operator->getKey(),
            'type' => BookingChargeType::Overstay->value,
            'description' => sprintf(
                '%d night%s overstay · %s → %s',
                $nights,
                $nights === 1 ? '' : 's',
                $rangeStart->toFormattedDateString(),
                $today->toFormattedDateString(),
            ),
            'amount' => $nights * $baseRate,
            'settled' => false,
        ]);
    }

    public static function trimNote(?string $note): ?string
    {
        $note = $note !== null ? trim($note) : null;
        if ($note === null || $note === '') {
            return null;
        }

        return Str::limit($note, self::NOTE_MAX - 3);
    }

    private function assertTakeMethod(string $method): void
    {
        if (! in_array($method, PaymentMethod::takeValues(), true)) {
            throw new RuntimeException('Pick a payment method.');
        }
    }
}
