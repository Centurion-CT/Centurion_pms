<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\PaymentStatus;
use App\Actions\Housekeeping\OpenRoomInspection;
use App\Enums\PaymentType;
use App\Mail\Marketplace\StaysReviewInvite;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Multitenancy\TenantScope;
use App\Services\Bookings\LateCheckoutFeeCalculator;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Operator-side check-out - terminal state. Releases any future-dated
 * Booked blocks (in case the guest departed early), preserving Blocked
 * rows. Mirrors the surgical deletion in {@see CancelBooking}.
 *
 * Only legal from CheckedIn (most cases) or directly from Confirmed/OnHold
 * (operator forgot to check in - common in practice).
 *
 * Overstay handling
 * -----------------
 * If `today > checkout_date`, the guest stayed past their booked window.
 * Before flipping the status we add an `Overstay` BookingCharge equal
 * to `room.base_rate × overstay_nights` (idempotent - re-running this
 * action won't double-charge). The standard `outstandingTotal()` guard
 * then refuses to complete the checkout until the operator settles the
 * new charge.
 *
 * Net effect: an overdue check-out always passes through a settlement
 * step. The UI surfaces the overstay total + the new outstanding so
 * the operator sees exactly what the guest owes before they leave.
 */
class MarkCheckedOut
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly LateCheckoutFeeCalculator $lateCheckoutFees,
    ) {}

    /**
     * @param  string  $earlyCheckoutRefund  one of 'keep', 'wallet', 'cash'.
     *  Only consulted when the guest is leaving before their booked
     *  checkout AND at least one paid night is unused. Callers derive
     *  the amount via {@see \App\Services\Bookings\EarlyCheckoutQuote}
     *  and pass the operator's pick; ignored on same-day / overstay
     *  checkouts. Default 'keep' preserves prior behaviour.
     */
    public function handle(Booking $booking, Operator $operator, string $earlyCheckoutRefund = 'keep'): Booking
    {
        $legal = [BookingStatus::CheckedIn, BookingStatus::Confirmed, BookingStatus::OnHold];
        if (! in_array($booking->booking_status, $legal, true)) {
            throw new RuntimeException(
                "Cannot check out a booking in state {$booking->booking_status->value}.",
            );
        }

        // ── Overstay calculation ────────────────────────────────────
        // `diffInDays` is half-open: a guest booked through the 10th who
        // checks out on the 11th has overstayed by 1 night. We only
        // add a charge for the DELTA that the nightly {@see
        // \App\Console\Commands\Bookings\AutoDeductFromWallet} hasn't
        // captured yet - under-billed guests (missed cron runs) get
        // topped up at checkout instead of walking out short.
        $today = Carbon::today();
        $checkoutDate = $booking->checkout_date->copy()->startOfDay();
        $overstayAddedNow = false;

        if ($today->greaterThan($checkoutDate)) {
            // Overstay is billed at the tier the guest committed to
            // (breakfast tier or base) so a breakfast-tier guest
            // doesn't get an overstay bill cheaper than what they
            // paid for every other night of their stay.
            $rate = $booking->committedNightlyRate();
            $overstayNights = $booking->unchargedOverstayNights($today);
            $overstayAmount = $rate * $overstayNights;
            $alreadyChargedNights = $booking->overstayNightsCharged();

            if ($overstayNights > 0 && $overstayAmount > 0) {
                // Uncharged range starts the day AFTER the last
                // already-billed overstay night so the description
                // reads truthfully alongside any auto charges.
                $rangeStart = $checkoutDate->copy()->addDays($alreadyChargedNights);

                BookingCharge::query()->create([
                    'tenant_id' => $booking->tenant_id,
                    'booking_id' => $booking->id,
                    'created_by_operator_id' => $operator->getKey(),
                    'type' => BookingChargeType::Overstay->value,
                    'description' => sprintf(
                        '%d night%s overstay · %s → %s',
                        $overstayNights,
                        $overstayNights === 1 ? '' : 's',
                        $rangeStart->toFormattedDateString(),
                        $today->toFormattedDateString(),
                    ),
                    'amount' => $overstayAmount,
                    'settled' => false,
                ]);
                $overstayAddedNow = true;

                activity('booking')
                    ->causedBy($operator)
                    ->performedOn($booking)
                    ->event('overstay_charged')
                    ->log(sprintf(
                        'Overstay fee ₦%s added to %s (%d night%s past %s).',
                        number_format($overstayAmount),
                        $booking->booking_ref,
                        $overstayNights,
                        $overstayNights === 1 ? '' : 's',
                        $checkoutDate->toDateString(),
                    ));

                // Refresh so outstandingTotal() picks up the new row.
                $booking = $booking->refresh();
            }
        }

        // ── Same-day late check-out fee ────────────────────────────
        // Distinct from overstay: overstay is nightly (guest stayed
        // past checkout_date), late-checkout is hourly (guest still
        // on-site past the property's check_out_to on the SAME day
        // they're due to leave). The calculator returns kind='none'
        // when policy is disabled, no time is configured, or the guest
        // is inside grace - this block no-ops in those cases.
        //
        // Idempotent by presence: if a LateCheckout charge already
        // exists on this booking we skip. Fee is captured at the first
        // successful call; retries after outstanding settlement won't
        // stack a second row on top.
        $lateCheckoutAddedNow = false;
        $property = $booking->room?->property ?? $booking->tenant?->property;
        if ($property !== null) {
            $existingLate = BookingCharge::query()
                ->where('booking_id', $booking->id)
                ->where('type', BookingChargeType::LateCheckout->value)
                ->exists();
            if (! $existingLate) {
                $quote = $this->lateCheckoutFees->quote($booking, $property, Carbon::now());
                if ($quote['kind'] !== 'none' && $quote['amount'] > 0) {
                    BookingCharge::query()->create([
                        'tenant_id' => $booking->tenant_id,
                        'booking_id' => $booking->id,
                        'created_by_operator_id' => $operator->getKey(),
                        'type' => BookingChargeType::LateCheckout->value,
                        'description' => $quote['description'],
                        'amount' => $quote['amount'],
                        'settled' => false,
                    ]);
                    $lateCheckoutAddedNow = true;

                    activity('booking')
                        ->causedBy($operator)
                        ->performedOn($booking)
                        ->event('late_checkout_charged')
                        ->withProperties([
                            'kind' => $quote['kind'],
                            'hours_late' => $quote['hours_late'],
                            'amount' => $quote['amount'],
                        ])
                        ->log(sprintf(
                            'Late check-out fee ₦%s added to %s (%s, %d hour%s late).',
                            number_format($quote['amount']),
                            $booking->booking_ref,
                            $quote['kind'] === 'full_day' ? 'full-day' : 'hourly',
                            $quote['hours_late'],
                            $quote['hours_late'] === 1 ? '' : 's',
                        ));

                    $booking = $booking->refresh();
                }
            }
        }

        // ── Early-checkout resolution ──────────────────────────────
        // Guest leaving before their booked checkout with unused paid
        // nights. Operator picks the resolution up-stream (in the
        // checkout modal) and we execute it here:
        //   - 'keep'   : silent non-refund, prior behaviour.
        //   - 'wallet' : credit the guest wallet for reuse next stay.
        //   - 'cash'   : record a negative Payment row (cash refund
        //                counted out at the desk).
        //
        // In every case we roll `checkout_date` back to today +
        // trim `nights` so occupancy + revenue reports don't count
        // the unused nights as stay revenue.
        if ($earlyCheckoutRefund !== 'keep' || ($earlyCheckoutRefund === 'keep' && $today->lessThan($checkoutDate))) {
            // Apply the property's cancellation-fee percent so the
            // refunded amount here matches what the operator saw in
            // the modal / what the policy dictates on auto-apply.
            $earlyPercent = (int) ($booking->room?->property?->checkoutPolicy()['early_checkout_refund_percent'] ?? 100);
            $earlyQuote = app(\App\Services\Bookings\EarlyCheckoutQuote::class)->quote($booking, $today, $earlyPercent);
            if ($earlyQuote['is_early']) {
                $refundAmount = $earlyQuote['refundable_amount'];
                if ($earlyCheckoutRefund === 'wallet' && $refundAmount > 0) {
                    $customerForRefund = $booking->customer;
                    if ($customerForRefund instanceof Customer) {
                        $this->wallet->topup(
                            customer: $customerForRefund,
                            amount: $refundAmount,
                            description: sprintf(
                                'Early-checkout refund on %s (%d unused night%s)',
                                $booking->booking_ref,
                                $earlyQuote['unused_nights'],
                                $earlyQuote['unused_nights'] === 1 ? '' : 's',
                            ),
                            meta: ['source' => 'early_checkout_refund', 'booking_ref' => $booking->booking_ref],
                            links: ['booking_id' => $booking->id],
                            causer: $operator,
                        );
                        $this->logEarlyResolution($booking, $operator, 'wallet', $refundAmount, $earlyQuote['unused_nights']);
                    }
                } elseif ($earlyCheckoutRefund === 'cash' && $refundAmount > 0) {
                    Payment::query()->create([
                        'tenant_id' => $booking->tenant_id,
                        'booking_id' => $booking->id,
                        'invoice_id' => null,
                        'paystack_reference' => 'refund-'.Str::ulid()->toBase32(),
                        'amount' => -$refundAmount,
                        'type' => PaymentType::Balance,
                        'method' => 'cash',
                        'reference_note' => sprintf(
                            'Early-checkout cash refund (%d unused night%s)',
                            $earlyQuote['unused_nights'],
                            $earlyQuote['unused_nights'] === 1 ? '' : 's',
                        ),
                        'status' => PaymentStatus::Refunded,
                        'paid_at' => Carbon::now(),
                        'collected_by_operator_id' => $operator->getKey(),
                    ]);
                    $this->logEarlyResolution($booking, $operator, 'cash', $refundAmount, $earlyQuote['unused_nights']);
                } else {
                    // 'keep' + early: log the decision so the audit
                    // trail shows the operator explicitly declined a refund.
                    $this->logEarlyResolution($booking, $operator, 'keep', 0, $earlyQuote['unused_nights']);
                }

                // Trim the booking to reflect the actual stay length.
                // For paid stays, room_total + total_amount stay as-is
                // (revenue collected for those nights is real; a
                // wallet/cash refund is a separate payment row).
                //
                // For CORPORATE-GUARANTEED UNPAID stays (the invoice-
                // later path) that logic inverts: no revenue has been
                // collected, so leaving total_amount at the full 5-
                // night value would just bill the corp for nights the
                // guest didn't use. Reduce room_total + total_amount +
                // balance_due by the unused amount so the invoice
                // reflects only the nights actually consumed.
                $updates = [
                    'checkout_date' => $today->toDateString(),
                    'nights' => max(1, $earlyQuote['actual_nights']),
                ];
                $isCorpUnpaid = $booking->isCorporatePreAuthorized()
                    && (int) $booking->balance_due > 0
                    && $earlyQuote['unused_amount'] > 0;
                if ($isCorpUnpaid) {
                    $unused = (int) $earlyQuote['unused_amount'];
                    $newRoomTotal = max(0, (int) $booking->room_total - $unused);
                    $newTotal = max(0, (int) $booking->total_amount - $unused);
                    $newBalance = max(0, (int) $booking->balance_due - $unused);
                    $updates['room_total'] = $newRoomTotal;
                    $updates['total_amount'] = $newTotal;
                    $updates['balance_due'] = $newBalance;
                    activity('booking')
                        ->causedBy($operator)
                        ->performedOn($booking)
                        ->event('corporate_early_checkout_trimmed')
                        ->withProperties([
                            'booking_ref' => $booking->booking_ref,
                            'unused_nights' => (int) $earlyQuote['unused_nights'],
                            'unused_amount' => $unused,
                            'new_total_amount' => $newTotal,
                        ])
                        ->log(sprintf(
                            'Corp early checkout: reduced total by ₦%s (%d unused night%s) — will bill only actual stay.',
                            number_format($unused),
                            $earlyQuote['unused_nights'],
                            $earlyQuote['unused_nights'] === 1 ? '' : 's',
                        ));
                }
                $booking->forceFill($updates)->save();
                $booking = $booking->refresh();
            }
        }

        // ── Auto-sweep unsettled charges from the guest wallet ─────
        // Runs BEFORE the outstanding guard so a wallet-funded guest
        // clears their folio without the operator needing to click
        // "Pay from wallet" for each charge. Silently skips guests
        // with no wallet / insufficient balance - the outstanding
        // guard below still refuses to release the room in that case.
        $customer = $booking->customer;
        if ($customer instanceof Customer && $customer->walletBalance() > 0) {
            $sweptCharges = $this->sweepChargesFromWallet($booking, $customer);
            $sweptBalance = $this->sweepBalanceFromWallet($booking, $customer, $operator);
            if ($sweptCharges > 0 || $sweptBalance > 0) {
                $booking = $booking->refresh();
            }
        }

        // ── Outstanding guard ───────────────────────────────────────
        // Defense in depth - the UI also blocks at outstandingTotal > 0,
        // but if a caller bypasses the UI we still refuse to release the
        // room while money is owed.
        //
        // Corporate-guaranteed stays are the exception: the org will
        // settle by invoice after checkout, so stranding the guest at
        // the counter for a bill nobody's going to pay in cash makes
        // no sense. The outstanding stays on the folio (balance_due
        // + unsettled charges) so the invoicing job can still see it.
        $outstanding = $booking->outstandingTotal();
        if ($outstanding > 0 && ! $booking->isCorporatePreAuthorized()) {
            $prefix = match (true) {
                $overstayAddedNow => 'Overstay fee added. ',
                $lateCheckoutAddedNow => 'Late check-out fee added. ',
                default => '',
            };
            throw new RuntimeException(
                $prefix.'Outstanding balance of ₦'.number_format($outstanding)
                .' must be cleared before check-out.'
            );
        }

        // ── Status flip + release future-dated availability blocks ──
        DB::transaction(function () use ($booking, $operator): void {
            AvailabilityBlock::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('booking_id', $booking->id)
                ->where('type', AvailabilityBlockType::Booked->value)
                ->delete();

            $booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();

            // Flip the room into Dirty so housekeeping knows it needs
            // attention before another guest can check in. Operator
            // flow: Dirty → housekeeper cleans → Clean → (optional)
            // supervisor inspects → Inspected.
            if ($booking->room !== null) {
                $booking->room->forceFill([
                    'housekeeping_status' => HousekeepingStatus::Dirty->value,
                    'housekeeping_updated_at' => Carbon::now(),
                    'housekeeping_updated_by_operator_id' => $operator->getKey(),
                ])->save();

                // Open a fresh room inspection so housekeeping → porter
                // → supervisor sign off before a new guest can check in.
                app(OpenRoomInspection::class)->handle($booking->room, $booking);
            }
        });

        activity('booking')
            ->causedBy($operator)
            ->performedOn($booking)
            ->event('checked_out')
            ->log("Operator {$operator->name} checked out {$booking->guest_name} ({$booking->booking_ref}).");

        // Keverna Stays post-stay review invite. Fires once per
        // booking (guarded by review_invite_sent_at), only for
        // marketplace-sourced bookings (source_note match). Failure
        // to queue must never break the checkout - we log + move on.
        $this->maybeQueueStaysReviewInvite($booking);

        return $booking->refresh();
    }

    /**
     * Queues the post-stay review invite for a marketplace booking.
     * Idempotent: writes review_invite_sent_at before dispatching so
     * a re-run of MarkCheckedOut can't double-send. Non-fatal: any
     * mail-queue failure is logged, not re-thrown.
     */
    private function maybeQueueStaysReviewInvite(Booking $booking): void
    {
        if ($booking->review_invite_sent_at !== null) return;
        if ((string) $booking->source_note !== 'Keverna Stays marketplace') return;
        if (trim((string) $booking->guest_email) === '') return;

        try {
            $reviewUrl = URL::temporarySignedRoute(
                'marketplace.stays.review.show',
                now()->addDays(90),
                ['booking_ref' => $booking->booking_ref],
            );

            $propertyName = (string) ($booking->room?->property?->name
                ?? $booking->tenant?->name
                ?? 'your stay');

            Mail::to($booking->guest_email)->queue(
                new StaysReviewInvite($booking, $propertyName, $reviewUrl),
            );

            $booking->forceFill(['review_invite_sent_at' => Carbon::now()])->save();
        } catch (Throwable $e) {
            Log::warning('Stays review invite queue failed', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logEarlyResolution(Booking $booking, Operator $operator, string $mode, int $amount, int $unusedNights): void
    {
        activity('booking')
            ->causedBy($operator)
            ->performedOn($booking)
            ->event('early_checkout_'.$mode)
            ->withProperties([
                'mode' => $mode,
                'amount' => $amount,
                'unused_nights' => $unusedNights,
            ])
            ->log(sprintf(
                'Early checkout on %s: %s (%d unused night%s%s).',
                $booking->booking_ref,
                match ($mode) {
                    'wallet' => 'credited ₦'.number_format($amount).' to wallet',
                    'cash'   => 'refunded ₦'.number_format($amount).' cash',
                    default  => 'no refund (kept as revenue)',
                },
                $unusedNights,
                $unusedNights === 1 ? '' : 's',
                $amount > 0 ? '' : '',
            ));
    }

    /**
     * Spend from the wallet to clear unsettled charges in id order,
     * stopping at the first charge the wallet can't cover in full.
     * Returns the number of charges settled.
     */
    private function sweepChargesFromWallet(Booking $booking, Customer $customer): int
    {
        $settled = 0;

        $charges = BookingCharge::query()
            ->where('booking_id', $booking->id)
            ->where('settled', false)
            ->orderBy('id')
            ->get();

        foreach ($charges as $charge) {
            $available = $customer->fresh()->walletBalance();
            if ($available < (int) $charge->amount) {
                break;
            }

            $this->wallet->spend(
                customer: $customer,
                amount: (int) $charge->amount,
                description: sprintf(
                    '%s charge on %s - %s',
                    $charge->type->label(),
                    $booking->booking_ref,
                    $charge->description,
                ),
                meta: ['source' => 'settle_charges', 'booking_ref' => $booking->booking_ref],
                links: ['booking_id' => $booking->id, 'booking_charge_id' => $charge->id],
            );

            $charge->forceFill([
                'settled' => true,
                'settled_at' => Carbon::now(),
                'settled_method' => 'wallet',
            ])->save();

            $settled++;
        }

        return $settled;
    }

    /**
     * If the wallet still has funds after charges are cleared, apply
     * whatever is available to the booking's balance_due. Any remainder
     * on the booking is caught by the outstanding guard downstream.
     */
    private function sweepBalanceFromWallet(Booking $booking, Customer $customer, Operator $operator): int
    {
        $balance = (int) ($booking->balance_due ?? 0);
        if ($balance <= 0) {
            return 0;
        }
        $available = $customer->fresh()->walletBalance();
        if ($available <= 0) {
            return 0;
        }
        $toSpend = min($balance, $available);

        return DB::transaction(function () use ($booking, $customer, $operator, $balance, $toSpend): int {
            $payment = Payment::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'invoice_id' => null,
                'paystack_reference' => 'wallet-'.Str::ulid()->toBase32(),
                'amount' => $toSpend,
                'type' => PaymentType::Balance,
                'method' => 'wallet',
                'reference_note' => 'Checkout wallet sweep',
                'status' => PaymentStatus::Succeeded,
                'paid_at' => Carbon::now(),
            ]);

            $this->wallet->spend(
                customer: $customer,
                amount: $toSpend,
                description: sprintf('Checkout wallet sweep on %s', $booking->booking_ref),
                meta: ['source' => 'pay_booking', 'booking_ref' => $booking->booking_ref],
                links: ['booking_id' => $booking->id, 'payment_id' => $payment->id],
                causer: $operator,
            );

            if ($toSpend >= $balance) {
                $booking->forceFill([
                    'payment_status' => PaymentStatus::Succeeded->value,
                    'balance_due' => 0,
                ])->save();
            } else {
                $booking->forceFill([
                    'balance_due' => $balance - $toSpend,
                ])->save();
            }

            return $toSpend;
        });
    }
}
