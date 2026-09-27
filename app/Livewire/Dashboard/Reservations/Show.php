<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Reservations;

use App\Actions\Bookings\AddBookingCharge;
use App\Actions\Bookings\AssignRoomToBooking;
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CancelBookingExtension;
use App\Actions\Bookings\IssueBookingCoupon;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Bookings\MarkNoShow;
use App\Actions\Bookings\ReassignRoom;
use App\Actions\Bookings\RecordBookingRefund;
use App\Actions\Bookings\RecordManualPayment;
use App\Actions\Bookings\RecordPartPayment;
use App\Actions\Bookings\SettleCharges;
use App\Actions\Bookings\SettleChargesFromWallet;
use App\Actions\Bookings\SettleChargesWalletFirst;
use App\Actions\Housekeeping\FrontDeskOverrideInspection;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomInspectionStatus;
use App\Models\RoomInspection;
use App\Livewire\Concerns\HasExtendStayModal;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\RoomAllocator;
use App\Services\Customers\CustomerLinker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Front-desk console for one reservation. Wires every operational
 * action, check in, check out, extend, no-show, cancel, add a charge,
 * settle a charge, reassign room (with rate reconciliation).
 *
 * Distinct from `tenant.bookings.show` (records / paper-trail view).
 * This page is built around what an operator does in the moment.
 */
#[Layout('layouts.tenant-auth')]
class Show extends Component
{
    use HasExtendStayModal;

    #[Locked]
    public int $bookingId;

    // Add-charge form
    public string $newChargeType = 'food_beverage';

    public string $newChargeDescription = '';

    public ?int $newChargeAmount = null;

    // Settle form
    public string $settleMethod = 'cash';

    // Room reassignment
    public bool $showReassign = false;

    public ?int $reassignTargetRoomId = null;

    /**
     * Category-only (unassigned) bookings need three extra actions
     * from this page:
     *   1. Pick a specific Room from the current category
     *   2. Change the category (switches room_category_id + reprices
     *      total_amount at the new base rate × nights).
     *   3. Confirm the booking + collect payment inline so it can be
     *      checked in the same day.
     * These fields drive the panels for those actions.
     */
    public ?int $assignRoomId = null;

    public ?int $switchCategoryId = null;

    public bool $showConfirmPay = false;

    public ?int $confirmPayAmount = null;

    public string $confirmPayMethod = 'cash';

    // Part-payment: collect an amount SMALLER than the full
    // outstanding when the guest can't clear it all in one go.
    // Sits next to Settle everything in the Charges & outstanding
    // section. Writes a Payment row + reduces balance_due by the
    // collected amount so subsequent settle actions see the true
    // remaining outstanding.
    public bool $partPayOpen = false;

    public ?int $partPayAmount = null;

    public string $partPayMethod = 'cash';

    // Rate-delta reconciliation, populated after a cross-rate reassign.
    // Delta sign: positive = guest owes more (collect); negative = due
    // a refund or coupon.
    public ?int $pendingReconcileDelta = null;

    public ?string $reconcileMode = null;    // 'collect' | 'refund' | 'coupon'

    public int $reconcileAmount = 0;

    public string $reconcileMethod = 'cash';

    public string $reconcileReason = '';

    public ?string $issuedCouponCode = null;

    // Extra reconciliation context surfaced to the UI so the operator
    // can pick between the remaining-nights (default) and full-nights
    // (assignment-correction) refund. These populate at the same time
    // as $pendingReconcileDelta.
    public int $reconcileRemainingNights = 0;

    public int $reconcileBookedNights = 0;

    public ?int $reconcileFullNightsDelta = null;

    public int $reconcileOldRate = 0;

    public int $reconcileNewRate = 0;

    // Banners
    /** Client-side hint for the blade to scroll a specific card into
     *  view on mount. Alpine reads $wire.get('scrollTarget') and
     *  scrolls the matching [data-scroll-anchor] element. */
    public string $scrollTarget = '';

    public function mount(string $bookingRef): void
    {
        $booking = Booking::query()->where('booking_ref', $bookingRef)->firstOrFail();
        $this->bookingId = $booking->id;

        // Deep-link support: /reservations/{ref}?{action}=1 opens the
        // page with a specific action-surface expanded / focused. Used
        // by the in-house detail panel + guest-requests page so the
        // operator lands on the right surface without a second click.
        $q = request()->query();
        if (($q['reassign'] ?? null) === '1') {
            $this->showReassign = true;
            $this->scrollTarget = 'reassign';
        }
        if (($q['extend'] ?? null) === '1') {
            $this->openExtendModal($this->bookingId);
        }
        if (($q['charge'] ?? null) === '1') {
            $this->scrollTarget = 'add-charge';
        }
        if (($q['settle'] ?? null) === '1') {
            $this->scrollTarget = 'settle';
        }
        if (($q['checkout'] ?? null) === '1') {
            $this->scrollTarget = 'checkout';
        }
    }

    public function checkIn(MarkCheckedIn $action, CustomerLinker $linker): void
    {
        $this->runActorAction(function () use ($action, $linker): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            $action->handle($booking, $operator);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.checked_in',
                subject: $booking->guest_name.' checked in',
                body: 'Room '.$booking->room?->name.' · '.Carbon::now()->toFormattedDateString(),
            );

            // If housekeeping was mid-turnover we still let the check-in
            // through (see MarkCheckedIn::handle), but surface a
            // warning toast so the front desk knows the room needs
            // cleaning attention.
            if ($action->pendingInspection !== null) {
                $this->dispatch('notify',
                    title: 'Checked in — room needs cleaning attention',
                    message: sprintf(
                        'Housekeeping is still on this room (waiting on %s). Follow up before the guest complains.',
                        $action->pendingInspection->status->waitingOn(),
                    ),
                    kind: 'warn',
                );
            } else {
                $this->dispatch('notify', title: 'Done', message: 'Checked in.', kind: 'ok');
            }
        });
    }

    // ── Front-desk housekeeping override + check-in ───────────────
    // When the room's inspection hasn't been signed off but the
    // front desk knows the room is ready, they can force-pass the
    // inspection here (with a required reason for the audit trail)
    // and immediately check the guest in — one round-trip.
    public string $overrideReason = '';

    public function overrideAndCheckIn(
        FrontDeskOverrideInspection $override,
        MarkCheckedIn $action,
        CustomerLinker $linker,
    ): void {
        $this->runActorAction(function () use ($override, $action, $linker): void {
            $booking = $this->booking();
            $operator = auth('web')->user();

            if (! $operator instanceof Operator
                || ! in_array($operator->role, [OperatorRole::Frontdesk, OperatorRole::Manager], true)) {
                throw new RuntimeException('Only front-desk staff or managers can run this override.');
            }
            if ($booking->room === null) {
                throw new RuntimeException('This reservation has no room assigned yet.');
            }

            $override->handle($booking->room, $operator, $this->overrideReason);
            $action->handle($booking->refresh(), $operator);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.checked_in',
                subject: $booking->guest_name.' checked in',
                body: 'Room '.$booking->room?->name.' · front-desk override · '.Carbon::now()->toFormattedDateString(),
            );

            $this->overrideReason = '';
            $this->dispatch('notify',
                title: 'Overridden + checked in',
                message: 'The override is logged against your operator.',
                kind: 'ok',
            );
        });
    }

    // Early-checkout resolution modal state. Populated by checkOut()
    // when the guest is leaving before their booked checkout with
    // unused paid nights; the blade opens the modal, operator picks a
    // resolution ('keep' | 'wallet' | 'cash'), completeCheckOut() runs
    // MarkCheckedOut with the pick.
    public bool $earlyCheckoutOpen = false;

    public int $earlyCheckoutUnusedNights = 0;

    public int $earlyCheckoutRefundable = 0;

    public int $earlyCheckoutPerNight = 0;

    public function checkOut(MarkCheckedOut $action, SettleCharges $settle, CustomerLinker $linker, \App\Services\Bookings\EarlyCheckoutQuote $quote): void
    {
        $this->runActorAction(function () use ($action, $linker, $quote): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            // Hard guard, operator should resolve outstanding before
            // checkout. We don't auto-settle, just block. Corporate-
            // guaranteed stays are the exception — the org will settle
            // by invoice after checkout, so blocking the desk here just
            // strands the guest at the counter for a bill nobody's
            // going to pay in person.
            if ($booking->outstandingTotal() > 0 && ! $booking->isCorporatePreAuthorized()) {
                throw new RuntimeException(
                    'Outstanding balance of ₦'.number_format($booking->outstandingTotal()).' must be cleared before check-out.'
                );
            }

            // Early-checkout with unused paid nights? Consult the
            // property's cancellation policy for the pre-selected
            // resolution + refund percent. When default_mode is 'ask'
            // (or unset), show the modal so the operator picks; on
            // any explicit mode, apply the policy silently — the
            // activity trail still logs the decision.
            $policy = $booking->room?->property?->checkoutPolicy() ?? [
                'early_checkout_default_mode' => 'ask',
                'early_checkout_refund_percent' => 100,
            ];
            $refundPercent = (int) $policy['early_checkout_refund_percent'];
            $defaultMode = (string) $policy['early_checkout_default_mode'];
            $q = $quote->quote($booking, refundPercent: $refundPercent);

            if ($q['is_early'] && $q['refundable_amount'] > 0 && $defaultMode === 'ask') {
                $this->earlyCheckoutOpen = true;
                $this->earlyCheckoutUnusedNights = $q['unused_nights'];
                $this->earlyCheckoutRefundable = $q['refundable_amount'];
                $this->earlyCheckoutPerNight = $q['per_night'];
                return;
            }

            // Auto-apply path — default_mode is 'keep' / 'wallet' /
            // 'cash'. MarkCheckedOut re-quotes internally using the
            // same policy percent so the ledger amount matches.
            $autoMode = $q['is_early'] && $defaultMode !== 'ask' ? $defaultMode : 'keep';
            $action->handle($booking, $operator, $autoMode);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.checked_out',
                subject: $booking->guest_name.' checked out',
                body: 'Total paid ₦'.number_format((int) $booking->total_amount),
            );
            $this->dispatch('notify', title: 'Done', message: 'Checked out, guest can be marked-cleaned in the room.', kind: 'ok');
        });
    }

    public function completeCheckOut(string $mode, MarkCheckedOut $action, CustomerLinker $linker): void
    {
        if (! in_array($mode, ['keep', 'wallet', 'cash'], true)) {
            $mode = 'keep';
        }
        $this->runActorAction(function () use ($mode, $action, $linker): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            $action->handle($booking, $operator, $mode);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.checked_out',
                subject: $booking->guest_name.' checked out',
                body: 'Total paid ₦'.number_format((int) $booking->total_amount),
            );
            $this->earlyCheckoutOpen = false;
            $this->earlyCheckoutUnusedNights = 0;
            $this->earlyCheckoutRefundable = 0;
            $this->earlyCheckoutPerNight = 0;
            $flash = match ($mode) {
                'wallet' => 'Checked out; unused nights credited to guest wallet.',
                'cash'   => 'Checked out; refund cash counted at the desk.',
                default  => 'Checked out; unused nights kept as revenue.',
            };
            $this->dispatch('notify', title: 'Done', message: $flash, kind: 'ok');
        });
    }

    public function closeEarlyCheckout(): void
    {
        $this->earlyCheckoutOpen = false;
    }

    // The old inline extend() has been retired — the shared
    // HasExtendStayModal trait ships submitExtend() which composes
    // ExtendBooking + ApplyPostCheckinDiscount + RecordManualPayment
    // in one action. The sidebar's "Extend stay" button now opens
    // that modal via openExtendModal($id).

    public function markNoShow(MarkNoShow $action, CustomerLinker $linker, bool $forfeit = true): void
    {
        $this->runActorAction(function () use ($action, $linker, $forfeit): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            $action->handle($booking, $operator, $forfeit);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.no_show',
                subject: $booking->guest_name.' marked no-show',
                body: $forfeit && (int) $booking->deposit_amount > 0
                    ? 'Deposit ₦'.number_format((int) $booking->deposit_amount).' forfeit'
                    : null,
            );
            $this->dispatch('notify', title: 'Done', message: 'Marked as no-show. Room re-entered inventory.', kind: 'ok');
        });
    }

    // ── Post-cancel refund modal ────────────────────────────────
    // If the reservation is cancelled while there's still cash on
    // it (paid − refunded > 0), auto-open a refund modal so the
    // operator can process the money back on the spot instead of
    // discovering it later on the Refunds page.
    public bool $showPostCancelRefund = false;

    public int $postCancelHeld = 0;

    public int $postCancelAmount = 0;

    public string $postCancelMethod = 'cash';

    public string $postCancelNote = '';

    public function cancel(CancelBooking $action, CustomerLinker $linker, bool $refund = false): void
    {
        $this->runActorAction(function () use ($action, $linker, $refund): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            $action->handle($booking, $operator, $refund);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.cancelled',
                subject: 'Booking '.$booking->booking_ref.' cancelled',
                body: $refund ? 'Refund requested' : 'No refund requested',
            );
            $this->dispatch('notify', title: 'Done', message: 'Reservation cancelled.', kind: 'ok');

            // Auto-prompt refund if there's held cash. Cap the pre-
            // filled amount at what's actually refundable so the
            // operator only has to pick a method + confirm.
            $held = $this->currentHeldCash();
            if ($held > 0) {
                $this->postCancelHeld = $held;
                $this->postCancelAmount = $held;
                $this->postCancelMethod = 'cash';
                $this->postCancelNote = '';
                $this->showPostCancelRefund = true;
            }
        });
    }

    public function closePostCancelRefund(): void
    {
        $this->showPostCancelRefund = false;
        $this->postCancelHeld = 0;
        $this->postCancelAmount = 0;
        $this->postCancelNote = '';
    }

    public function submitPostCancelRefund(RecordBookingRefund $refund): void
    {
        $this->runActorAction(function () use ($refund): void {
            if (! $this->showPostCancelRefund) {
                return;
            }
            $booking = $this->booking();
            $operator = auth('web')->user();
            if (! $operator instanceof Operator) {
                throw new RuntimeException('You must be signed in.');
            }
            $amount = (int) $this->postCancelAmount;
            $held = $this->currentHeldCash();
            if ($amount <= 0) {
                throw new RuntimeException('Enter a positive refund amount.');
            }
            if ($amount > $held) {
                throw new RuntimeException('Held is ₦'.number_format($held).'. Enter that or less.');
            }
            if (! in_array($this->postCancelMethod, ['cash', 'transfer', 'card', 'paystack', 'other'], true)) {
                throw new RuntimeException('Pick a valid refund method.');
            }

            $refund->handle(
                booking: $booking,
                operator: $operator,
                amount: $amount,
                method: $this->postCancelMethod,
                referenceNote: trim($this->postCancelNote) !== '' ? trim($this->postCancelNote) : null,
            );

            $this->dispatch('notify',
                title: 'Refund recorded',
                message: '₦'.number_format($amount).' refunded via '.$this->postCancelMethod.'.',
                kind: 'ok',
            );
            $this->closePostCancelRefund();
        });
    }

    /**
     * Held cash on this booking right now = succeeded payments
     * minus any refunds already issued. Used by the post-cancel
     * refund modal to pre-fill + cap.
     */
    private function currentHeldCash(): int
    {
        $paid = (int) \App\Models\Payment::query()
            ->where('booking_id', $this->bookingId)
            ->where('status', PaymentStatus::Succeeded->value)
            ->sum('amount');
        $refunded = (int) \App\Models\Payment::query()
            ->where('booking_id', $this->bookingId)
            ->where('status', PaymentStatus::Refunded->value)
            ->sum('amount');
        return max(0, $paid - $refunded);
    }

    public function addCharge(AddBookingCharge $action): void
    {
        $this->validate([
            'newChargeType' => ['required', 'string'],
            'newChargeDescription' => ['required', 'string', 'max:240'],
            'newChargeAmount' => ['required', 'integer', 'min:1'],
        ]);
        $this->runActorAction(function () use ($action): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            $type = BookingChargeType::from($this->newChargeType);
            $action->handle($booking, $operator, $type, $this->newChargeDescription, (int) $this->newChargeAmount);

            $this->newChargeDescription = '';
            $this->newChargeAmount = null;
            $this->dispatch('notify', title: 'Done', message: 'Charge added.', kind: 'ok');
        });
    }

    /**
     * Undo an unsettled Extension charge - rolls the checkout date
     * back, releases the extension nights from availability, deletes
     * the charge row. Refuses if the guest has already paid for the
     * extension (that path goes through the refund flow instead).
     */
    public function cancelExtension(int $chargeId, CancelBookingExtension $action): void
    {
        $this->runActorAction(function () use ($chargeId, $action): void {
            $charge = BookingCharge::query()
                ->where('booking_id', $this->bookingId)
                ->findOrFail($chargeId);
            $operator = auth('web')->user();

            $action->handle($charge, $operator);
            $this->dispatch('notify', title: 'Done', message: 'Extension cancelled and nights released.', kind: 'ok');
        });
    }

    public function settleOne(int $chargeId, SettleCharges $settle, SettleChargesFromWallet $wallet): void
    {
        $this->runActorAction(function () use ($chargeId, $settle, $wallet): void {
            $charge = BookingCharge::query()->where('booking_id', $this->bookingId)->findOrFail($chargeId);
            $operator = auth('web')->user();

            if ($this->settleMethod === 'wallet') {
                $ok = $wallet->handleOne($charge, $operator);
                if (! $ok) {
                    throw new RuntimeException(
                        'Wallet has no balance or not enough to cover this charge, pick another method.',
                    );
                }
                $this->dispatch('notify', title: 'Done', message: 'Charge paid from wallet.', kind: 'ok');

                return;
            }

            $settle->settleOne($charge, $operator, $this->settleMethod);
            $this->dispatch('notify', title: 'Done', message: 'Charge marked paid.', kind: 'ok');
        });
    }

    // ── Part payment ────────────────────────────────────────────

    public function togglePartPay(): void
    {
        $this->partPayOpen = ! $this->partPayOpen;
        if ($this->partPayOpen) {
            $this->partPayAmount = null;
            $this->partPayMethod = 'cash';
        }
    }

    /**
     * Collect an amount SMALLER than the total outstanding. The
     * guest may only have part of what they owe at the counter; the
     * operator books what they hand over now and the outstanding
     * drops by exactly that amount.
     *
     * Application order (so outstandingTotal reduces by exactly the
     * payment amount):
     *   1. balance_due first (floors at 0).
     *   2. Any remaining payment chips unsettled charges oldest-
     *      first: fully-settles when the payment covers the full
     *      charge, else reduces the charge amount by the remaining
     *      payment and stops.
     *   3. One Payment row via RecordManualPayment records the
     *      receipt; the internal cap is total_amount - alreadyPaid
     *      so an over-collection routes to the wallet.
     */
    public function takePartPayment(RecordPartPayment $record): void
    {
        $this->runActorAction(function () use ($record): void {
            $booking = $this->booking();
            $outstanding = $booking->outstandingTotal();
            $amount = (int) ($this->partPayAmount ?? 0);
            $operator = auth('web')->user();
            if (! $operator instanceof Operator) {
                throw new RuntimeException('You must be signed in.');
            }

            $record->handle($booking, $operator, $amount, $this->partPayMethod);

            $remaining = max(0, $outstanding - $amount);
            $this->partPayOpen = false;
            $this->partPayAmount = null;
            $this->dispatch('notify',
                title: 'Part payment recorded',
                message: sprintf(
                    '₦%s taken via %s. %s',
                    number_format($amount),
                    $this->partPayMethod,
                    $remaining > 0
                        ? '₦'.number_format($remaining).' still outstanding.'
                        : 'Booking is now settled.',
                ),
                kind: 'ok',
            );
        });
    }

    public function settleAll(
        SettleCharges $settle,
        SettleChargesFromWallet $wallet,
        SettleChargesWalletFirst $walletFirst,
    ): void {
        $this->runActorAction(function () use ($wallet, $walletFirst): void {
            $booking = $this->booking();
            $operator = auth('web')->user();

            // Materialize any projected-but-not-yet-billed overstay
            // as a real BookingCharge first. Otherwise "Settle
            // everything" would only clear the ledger lines the
            // operator can already see, leaving the ₦N still-to-bill
            // overstay hanging until MarkCheckedOut runs — and the
            // amount they collected wouldn't match the true
            // outstanding shown on the tile.
            $this->materialiseUnbilledOverstay($booking, $operator);
            $booking = $booking->refresh();

            if ($this->settleMethod === 'wallet') {
                // Wallet-only: don't touch cash, stop when the balance
                // can't cover the next charge (charges aren't split).
                $count = $wallet->handle($booking, $operator);
                if ($count === 0) {
                    throw new RuntimeException(
                        'Wallet is empty or nothing outstanding could be covered, pick another method.',
                    );
                }
                $this->dispatch('notify', title: 'Done', message: "Settled {$count} charge(s) from wallet.", kind: 'ok');

                return;
            }

            // Any other method: drain the wallet first (splitting a
            // charge if the balance falls between rows), then sweep
            // the shortfall with the picked method.
            $result = $walletFirst->handle($booking, $operator, $this->settleMethod);

            $bits = [];
            if ($result['wallet'] > 0) {
                $bits[] = '₦'.number_format($result['wallet']).' from wallet';
            }
            if ($result['fallback_cleared'] > 0) {
                $bits[] = $result['fallback_cleared'].' charge(s) via '.$this->settleMethod;
            }

            $this->dispatch('notify',
                title: 'Done',
                message: $bits === [] ? 'Nothing to settle.' : 'Settled '.implode(' + ', $bits).'.',
                kind: 'ok',
            );
        });
    }

    /**
     * If overstay has accrued but the nightly auto-cron hasn't caught
     * up (or ran but couldn't run for some reason), promote the
     * remainder to a real BookingCharge so the operator can settle
     * it inline. Mirrors the top-up path in MarkCheckedOut so the
     * numbers stay consistent across both entry points.
     */
    private function materialiseUnbilledOverstay(Booking $booking, Operator $operator): void
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
        $amount = $nights * $baseRate;
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
            'amount' => $amount,
            'settled' => false,
        ]);
    }

    public function toggleReassign(): void
    {
        $this->showReassign = ! $this->showReassign;
        $this->reassignTargetRoomId = null;
        $this->resetReconcileState();
    }

    /**
     * Pin a specific Room to a category-only (unassigned) booking.
     * Runs the same AssignRoomToBooking guardrails as the records-
     * view page (same tenant, same category, room free for the
     * booking's date range). Once a room is assigned the check-in
     * button unlocks (subject to payment / Confirmed status).
     */
    public function assignRoom(AssignRoomToBooking $action): void
    {
        if ($this->assignRoomId === null) {
            $this->dispatch('notify', title: 'Pick a room first', message: 'Choose a room from the list.', kind: 'err');

            return;
        }
        $this->runActorAction(function () use ($action): void {
            $room = Room::query()->find($this->assignRoomId);
            if ($room === null) {
                throw new RuntimeException('Room not found.');
            }
            $action->handle($this->booking(), $room, auth('web')->user());
            $this->assignRoomId = null;
            $this->dispatch('notify', title: 'Room assigned', message: "Room {$room->room_number} assigned. Take payment + check in when ready.", kind: 'ok');
        });
    }

    /**
     * Swap the booking's room_category_id to a different category
     * (guest asked for a different tier). Only allowed while
     * unassigned; once a room is pinned use ReassignRoom instead.
     * Reprices total_amount to nights × new-category.base_rate.
     */
    public function changeCategory(): void
    {
        if ($this->switchCategoryId === null) {
            $this->dispatch('notify', title: 'Pick a category', message: 'Choose a category to switch to.', kind: 'err');

            return;
        }
        $this->runActorAction(function (): void {
            $booking = $this->booking();
            if ($booking->room_id !== null) {
                throw new RuntimeException('Room is already assigned. Use "Change" on the room row to reassign.');
            }
            $newCategory = RoomCategory::query()->find($this->switchCategoryId);
            if ($newCategory === null) {
                throw new RuntimeException('Category not found.');
            }
            if ($newCategory->tenant_id !== $booking->tenant_id) {
                throw new RuntimeException('Category belongs to a different tenant.');
            }
            $oldCategory = $booking->roomCategory;
            if ($oldCategory !== null && $oldCategory->id === $newCategory->id) {
                throw new RuntimeException('Booking is already on that category.');
            }

            $nights = (int) $booking->nights;
            $oldTotal = (int) $booking->total_amount;
            $oldRoomTotal = (int) $booking->room_total;
            $oldBalanceDue = (int) $booking->balance_due;
            $oldRate = (int) ($oldCategory?->base_rate ?? 0);
            $newRate = (int) $newCategory->base_rate;
            $newTotal = $nights * $newRate;
            $newRoomTotal = $newTotal;
            $delta = $newTotal - $oldTotal;
            // Balance_due only moves when the guest has actually
            // paid something. For an unsettled Pending booking
            // (payment_status = Pending, balance_due = 0), the
            // Confirm-and-pay flow reads total_amount when it fires;
            // adding the delta to 0 here gave the operator a phantom
            // "₦15k owed" even though nothing had been collected yet.
            //
            // When a deposit HAS landed (Confirmed / OnHold), the
            // delta reconciles onto balance_due:
            //   * higher rate → guest owes more
            //   * lower rate → floors at 0; surplus is an operator
            //     reconciliation task (wallet credit / coupon)
            $isSettled = $booking->payment_status !== \App\Enums\PaymentStatus::Pending;
            $newBalanceDue = $isSettled
                ? max(0, $oldBalanceDue + $delta)
                : $oldBalanceDue;

            $booking->forceFill([
                'room_category_id' => $newCategory->id,
                'room_total'       => $newRoomTotal,
                'total_amount'     => $newTotal,
                'balance_due'      => $newBalanceDue,
            ])->save();

            // Repoint the availability blocks so they track the new
            // category (blocks were inserted with room_id NULL +
            // room_category_id at reserve time).
            \App\Models\AvailabilityBlock::query()
                ->where('booking_id', $booking->id)
                ->whereNull('room_id')
                ->update(['room_category_id' => $newCategory->id]);

            activity('booking')
                ->causedBy(auth('web')->user())
                ->performedOn($booking)
                ->event('category_changed')
                ->withProperties([
                    'booking_ref'     => $booking->booking_ref,
                    'old_category_id' => $oldCategory?->id,
                    'old_category'    => $oldCategory?->name,
                    'new_category_id' => $newCategory->id,
                    'new_category'    => $newCategory->name,
                    'old_nightly_rate'=> $oldRate,
                    'new_nightly_rate'=> $newRate,
                    'nights'          => $nights,
                    'old_total'       => $oldTotal,
                    'new_total'       => $newTotal,
                    'rate_delta'      => $delta,
                    'old_balance_due' => $oldBalanceDue,
                    'new_balance_due' => $newBalanceDue,
                ])
                ->log(sprintf(
                    'Category changed from %s to %s (rate %s · new total ₦%s).',
                    $oldCategory?->name ?? '-',
                    $newCategory->name,
                    $delta === 0 ? 'unchanged' : ($delta > 0 ? '+' : '−').'₦'.number_format(abs($delta)),
                    number_format($newTotal),
                ));

            $this->switchCategoryId = null;
            // Notify copy tracks the actual ledger state. For a
            // Pending booking (nothing paid), the delta talk is
            // misleading - the guest will owe the FULL new total
            // when Confirm & take payment fires, not just the delta.
            // Settled bookings still get the "+₦N to collect" /
            // "-₦N owed" hint since balance_due did just shift.
            if (! $isSettled) {
                $message = sprintf(
                    'Category swapped to %s. New total ₦%s. Payment collects on Confirm & take payment.',
                    $newCategory->name, number_format($newTotal),
                );
            } elseif ($delta === 0) {
                $message = sprintf('Category swapped to %s. No rate change.', $newCategory->name);
            } elseif ($delta > 0) {
                $message = sprintf(
                    'Category swapped to %s. New total ₦%s (+₦%s to collect).',
                    $newCategory->name, number_format($newTotal), number_format($delta),
                );
            } else {
                $message = sprintf(
                    'Category swapped to %s. New total ₦%s (-₦%s owed to guest).',
                    $newCategory->name, number_format($newTotal), number_format(abs($delta)),
                );
            }
            $this->dispatch('notify',
                title: 'Category changed',
                message: $message,
                kind: 'ok',
            );
        });
    }

    /**
     * Open the inline "Confirm & take payment" form. Seeds the
     * amount with total_amount so the operator only has to pick a
     * method + click for the common "pay the full stay in cash"
     * path. Category-only bookings land as Pending; this action
     * moves them to Confirmed + Succeeded and creates a Payment row.
     */
    public function toggleConfirmPay(): void
    {
        $this->showConfirmPay = ! $this->showConfirmPay;
        if ($this->showConfirmPay) {
            $this->confirmPayAmount = (int) $this->booking()->total_amount;
            $this->confirmPayMethod = 'cash';
        }
    }

    /**
     * Move a Pending category-only booking to Confirmed + Succeeded
     * and record the manual payment. RecordManualPayment writes a
     * Payment row (type=Full since no prior payment) and routes any
     * surplus to the guest's wallet if a customer is linked.
     */
    public function confirmAndPay(RecordManualPayment $record): void
    {
        $this->runActorAction(function () use ($record): void {
            $booking = $this->booking();
            if ($booking->booking_status !== BookingStatus::Pending) {
                throw new RuntimeException('Only Pending bookings can be confirmed from here. Use Extend / Add charge for other cases.');
            }
            if ($this->confirmPayAmount === null || $this->confirmPayAmount <= 0) {
                throw new RuntimeException('Enter how much the guest handed over.');
            }
            if (! in_array($this->confirmPayMethod, ['cash', 'transfer', 'card', 'pos', 'other'], true)) {
                throw new RuntimeException('Pick a payment method.');
            }

            $total = (int) $booking->total_amount;
            $covers = (int) $this->confirmPayAmount >= $total;

            // Bump balance_due to the total so RecordManualPayment's
            // idempotency check knows there's actually money owed.
            // The action caps the Payment row at total_amount and
            // routes any surplus to the guest wallet.
            $booking->forceFill([
                'balance_due'    => $total,
                'booking_status' => $covers ? BookingStatus::Confirmed : BookingStatus::OnHold,
                'payment_status' => PaymentStatus::Succeeded,
            ])->save();

            $record->handle(
                $booking->refresh(),
                auth('web')->user(),
                amount: (int) $this->confirmPayAmount,
                method: $this->confirmPayMethod,
            );

            // For the OnHold path (partial payment), balance_due
            // still holds the remainder correctly since
            // RecordManualPayment doesn't touch balance_due, so we
            // subtract the collected amount here.
            $booking = $booking->refresh();
            if (! $covers) {
                $booking->forceFill([
                    'balance_due' => max(0, $total - (int) $this->confirmPayAmount),
                ])->save();
            } else {
                $booking->forceFill(['balance_due' => 0])->save();
            }

            $this->showConfirmPay = false;
            $this->confirmPayAmount = null;
            $this->dispatch('notify',
                title: $covers ? 'Confirmed & paid' : 'Deposit taken, on hold',
                message: sprintf('₦%s recorded via %s.',
                    number_format((int) $this->confirmPayAmount ?: $total),
                    $this->confirmPayMethod,
                ),
                kind: 'ok',
            );
        });
    }

    /**
     * File an approval request to check the guest in without taking
     * payment upfront. Creates a GuestActionApproval row with kind
     * 'checkin_no_payment' and pings managers via email. The approval
     * lands in the Approvals module; on approve, the reservation is
     * flipped to Confirmed with balance_due = total_amount and
     * MarkCheckedIn runs. On reject, no state changes.
     */
    public function requestCheckinWithoutPayment(): void
    {
        $this->runActorAction(function (): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            if (! $operator instanceof Operator) {
                throw new RuntimeException('You must be signed in.');
            }
            if ($booking->booking_status !== BookingStatus::Pending) {
                throw new RuntimeException('Only Pending bookings need this — the guest can already be checked in.');
            }
            if ($booking->room_id === null) {
                throw new RuntimeException('Assign a room first, then request approval.');
            }
            if ($booking->isCorporatePreAuthorized()) {
                throw new RuntimeException('This booking is corporate-guaranteed. Check in directly, no approval needed.');
            }

            $existing = \App\Models\GuestActionApproval::query()
                ->where('booking_id', $booking->id)
                ->where('kind', 'checkin_no_payment')
                ->where('status', 'pending')
                ->exists();
            if ($existing) {
                throw new RuntimeException('An approval is already pending for this booking.');
            }

            $approval = \App\Models\GuestActionApproval::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'room_id' => $booking->room_id,
                'kind' => 'checkin_no_payment',
                'payload' => [
                    'requested_by_operator_id' => $operator->getKey(),
                    'requested_by_operator_name' => $operator->name,
                    'total_amount' => (int) $booking->total_amount,
                    'guest_name' => $booking->guest_name,
                ],
                'coord_score' => 0,
                'guest_latitude' => 0,
                'guest_longitude' => 0,
                'distance_meters' => 0,
                'status' => 'pending',
            ]);

            // Mail every manager on this tenant — they're the only role
            // that can approve. Silently swallowed mail errors are OK
            // here (the approval still lives in the queue for the UI).
            try {
                $managers = Operator::query()
                    ->where('role', OperatorRole::Manager->value)
                    ->whereNotNull('email')
                    ->get();
                foreach ($managers as $mgr) {
                    if ((string) $mgr->email !== '') {
                        \Illuminate\Support\Facades\Mail::to((string) $mgr->email)
                            ->queue(new \App\Mail\Approvals\CheckinApprovalRequestedMail(
                                $booking->refresh(),
                                $approval,
                                $operator,
                            ));
                    }
                }
            } catch (\Throwable) {
                // Mail dispatch is best-effort — the approval row is
                // the source of truth for the queue.
            }

            $this->dispatch('notify',
                title: 'Approval requested',
                message: 'A manager needs to approve. You\'ll see the reservation flip once they do.',
                kind: 'ok',
            );
        });
    }

    /**
     * Corporate-guaranteed direct check-in. Skips the Confirm & take
     * payment gate because the corp has already agreed to settle
     * the folio (their pre-negotiated rate covers the stay). Flips
     * Pending → Confirmed with balance_due = total_amount so the
     * outstanding shows on the folio, then runs MarkCheckedIn.
     */
    public function checkInAsCorporate(MarkCheckedIn $action, CustomerLinker $linker): void
    {
        $this->runActorAction(function () use ($action, $linker): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            if (! $operator instanceof Operator) {
                throw new RuntimeException('You must be signed in.');
            }
            if ($booking->booking_status !== BookingStatus::Pending) {
                throw new RuntimeException('Only Pending bookings use this shortcut.');
            }
            if (! $booking->isCorporatePreAuthorized()) {
                throw new RuntimeException('This booking is not corporate-guaranteed. Use Confirm & take payment or request approval.');
            }
            if ($booking->room_id === null) {
                throw new RuntimeException('Assign a room first.');
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $action, $operator, $linker): void {
                $booking->forceFill([
                    'booking_status' => BookingStatus::Confirmed,
                    'balance_due' => (int) $booking->total_amount,
                ])->save();
                $action->handle($booking->refresh(), $operator);
                $linker->recordBookingEvent(
                    booking: $booking->refresh(),
                    event: 'booking.checked_in',
                    subject: $booking->guest_name.' checked in (corp guaranteed)',
                    body: 'Room '.$booking->room?->name.' · '.Carbon::now()->toFormattedDateString(),
                );
            });

            if ($action->pendingInspection !== null) {
                $this->dispatch('notify',
                    title: 'Checked in — room needs cleaning attention',
                    message: sprintf(
                        'Corporate is guarantor. Housekeeping is still on this room (waiting on %s), follow up before the guest complains.',
                        $action->pendingInspection->status->waitingOn(),
                    ),
                    kind: 'warn',
                );
            } else {
                $this->dispatch('notify',
                    title: 'Checked in',
                    message: 'Corporate is guarantor — outstanding is on the folio.',
                    kind: 'ok',
                );
            }
        });
    }

    public function reassignRoom(ReassignRoom $action, CustomerLinker $linker): void
    {
        if ($this->reassignTargetRoomId === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Pick a room to swap to first.', kind: 'err');

            return;
        }
        $this->runActorAction(function () use ($action, $linker): void {
            $booking = $this->booking();
            $newRoom = Room::query()->findOrFail($this->reassignTargetRoomId);
            $oldRoomNumber = $booking->room?->room_number ?? '-';

            try {
                $result = $action->handle($booking, $newRoom, auth('web')->user());
            } catch (RangeNotAvailableException) {
                throw new RuntimeException('That room was just taken, pick another.');
            }

            $linker->recordBookingEvent(
                booking: $result->booking,
                event: 'booking.room_reassigned',
                subject: 'Room changed from '.$oldRoomNumber.' to '.($newRoom->room_number ?? '-'),
                body: $result->rateDelta !== 0
                    ? 'Rate delta: '.($result->rateDelta > 0 ? '+' : '−').'₦'.number_format(abs($result->rateDelta))
                    : null,
            );

            $this->reassignTargetRoomId = null;

            if ($result->rateDelta === 0) {
                // Nothing to reconcile, clean close.
                $this->showReassign = false;
                $this->dispatch('notify', title: 'Done', message: 'Reassigned to room '.($newRoom->room_number ?? '-').'.', kind: 'ok');

                return;
            }

            // Cross-rate reassignment: open the reconciliation step.
            // Keep the panel open, pre-populate defaults so the operator
            // just picks a method and confirms.
            $this->pendingReconcileDelta = $result->rateDelta;
            $this->reconcileAmount = abs($result->rateDelta);
            $this->reconcileMode = $result->rateDelta > 0 ? 'collect' : 'refund';
            $this->reconcileReason = 'Room change · '.$oldRoomNumber.' → '.($newRoom->room_number ?? '-');
            $this->reconcileRemainingNights = $result->remainingNights;
            $this->reconcileBookedNights = $result->nights;
            $this->reconcileFullNightsDelta = $result->fullNightsDelta;
            $this->reconcileOldRate = $result->oldNightlyRate;
            $this->reconcileNewRate = $result->newNightlyRate;
            $this->dispatch('notify',
                title: 'Done',
                message: 'Reassigned to room '.($newRoom->room_number ?? '-').'. '
                    .($result->rateDelta > 0
                        ? 'Collect ₦'.number_format(abs($result->rateDelta)).' from the guest.'
                        : 'Refund ₦'.number_format(abs($result->rateDelta)).' or issue a coupon to the guest.'),
                kind: 'ok',
            );
        });
    }

    public function chooseReconcileMode(string $mode): void
    {
        if (! in_array($mode, ['collect', 'refund', 'coupon'], true)) {
            return;
        }
        if ($this->pendingReconcileDelta === null) {
            return;
        }
        // Collect is only for positive deltas; refund + coupon only
        // for negative. Guard server-side even though the UI hides the
        // wrong option.
        $isPositive = $this->pendingReconcileDelta > 0;
        if (($isPositive && $mode !== 'collect') || (! $isPositive && $mode === 'collect')) {
            return;
        }
        $this->reconcileMode = $mode;
    }

    public function confirmReconcile(
        RecordManualPayment $collect,
        RecordBookingRefund $refund,
        IssueBookingCoupon $coupon,
    ): void {
        if ($this->pendingReconcileDelta === null || $this->reconcileMode === null) {
            return;
        }

        $this->runActorAction(function () use ($collect, $refund, $coupon): void {
            $booking = $this->booking();
            $operator = auth('web')->user();
            if (! $operator instanceof Operator) {
                throw new RuntimeException('You must be signed in.');
            }
            $amount = (int) $this->reconcileAmount;
            if ($amount <= 0) {
                throw new RuntimeException('Enter a positive amount to reconcile.');
            }

            $method = in_array($this->reconcileMethod, ['cash', 'transfer', 'card', 'paystack', 'other'], true)
                ? $this->reconcileMethod
                : 'cash';
            $reason = $this->reconcileReason !== '' ? $this->reconcileReason : null;

            switch ($this->reconcileMode) {
                case 'collect':
                    // RecordManualPayment's idempotency compares total-paid
                    // against the passed amount, so pass the new grand
                    // total (already paid + this top-up).
                    $alreadyPaid = (int) $booking->payments()
                        ->where('status', PaymentStatus::Succeeded)
                        ->sum('amount');
                    $collect->handle(
                        booking: $booking,
                        operator: $operator,
                        amount: $alreadyPaid + $amount,
                        method: $method,
                        referenceNote: $reason,
                    );
                    $this->dispatch('notify', title: 'Done', message: 'Collected ₦'.number_format($amount).' via '.$this->reconcileMethod.'.', kind: 'ok');
                    break;

                case 'refund':
                    $refund->handle(
                        booking: $booking,
                        operator: $operator,
                        amount: $amount,
                        method: $method,
                        referenceNote: $reason,
                    );
                    $this->dispatch('notify', title: 'Done', message: 'Refunded ₦'.number_format($amount).' via '.$this->reconcileMethod.'.', kind: 'ok');
                    break;

                case 'coupon':
                    $discount = $coupon->handle(
                        booking: $booking,
                        operator: $operator,
                        amount: $amount,
                        reason: $reason,
                    );
                    $this->issuedCouponCode = $discount->code;
                    $this->dispatch('notify', title: 'Done', message: 'Coupon issued: '.$discount->code.' worth ₦'.number_format($amount).'.', kind: 'ok');

                    // Keep the panel open so the operator can copy /
                    // share the code, but clear the reconcile state so
                    // we don't double-issue.
                    $this->reconcileMode = null;
                    $this->pendingReconcileDelta = null;

                    return;
            }

            // Collect / refund: close the panel entirely.
            $this->resetReconcileState();
            $this->showReassign = false;
        });
    }

    public function skipReconcile(): void
    {
        $this->resetReconcileState();
        $this->showReassign = false;
        $this->dispatch('notify', title: 'Done', message: 'Reassignment saved. The rate delta was NOT reconciled, record it manually if needed.', kind: 'ok');
    }

    public function dismissCoupon(): void
    {
        $this->issuedCouponCode = null;
        $this->resetReconcileState();
        $this->showReassign = false;
    }

    private function resetReconcileState(): void
    {
        $this->pendingReconcileDelta = null;
        $this->reconcileMode = null;
        $this->reconcileAmount = 0;
        $this->reconcileMethod = 'cash';
        $this->reconcileReason = '';
        $this->issuedCouponCode = null;
        $this->reconcileRemainingNights = 0;
        $this->reconcileBookedNights = 0;
        $this->reconcileFullNightsDelta = null;
        $this->reconcileOldRate = 0;
        $this->reconcileNewRate = 0;
    }

    /**
     * Switch the reconcile suggestion to cover every booked night, not
     * just the remaining ones. Front desk reaches for this when the
     * reassign is fixing a wrong-room placement (guest slept 1 night
     * in the wrong room; property owns that mistake so all 4 nights
     * should be credited, not just the remaining 3).
     *
     * Adjusts the booking's total_amount / room_total by the extra
     * delta so the ledger stays balanced after the larger refund lands.
     */
    public function useFullNightsRefund(): void
    {
        if ($this->pendingReconcileDelta === null || $this->reconcileFullNightsDelta === null) {
            return;
        }

        // Only meaningful when the full-nights delta is larger in
        // magnitude than the remaining-only one — i.e., there are past
        // nights that would go uncredited under the default suggestion.
        $extra = abs($this->reconcileFullNightsDelta) - abs($this->pendingReconcileDelta);
        if ($extra <= 0) {
            return;
        }

        $booking = $this->booking();
        $isRefund = $this->reconcileFullNightsDelta < 0;
        // Refund path: drop total_amount + room_total by the extra
        // magnitude so paid ends up matching the corrected total once
        // the larger refund lands. Collect path is the mirror — we
        // don't ship that here because upcharging past nights isn't a
        // hospitality convention operators reach for.
        if ($isRefund) {
            $booking->forceFill([
                'total_amount' => max(0, (int) $booking->total_amount - $extra),
                'room_total'   => max(0, (int) $booking->room_total - $extra),
                'balance_due'  => max(0, (int) $booking->balance_due - $extra),
            ])->save();
        }

        $this->pendingReconcileDelta = $this->reconcileFullNightsDelta;
        $this->reconcileAmount = abs($this->reconcileFullNightsDelta);
    }

    public function render(RoomAllocator $allocator): View
    {
        $booking = $this->booking();
        $charges = $booking->charges()->get();
        $unsettledTotal = (int) $charges->where('settled', false)->sum('amount');
        $settledTotal = (int) $charges->where('settled', true)->sum('amount');

        // Reassign candidates, every active room in the property that
        // is free for the booking's existing date range, cross-category.
        // Front desk needs to see all options, not just the guest's
        // originally-booked tier.
        $reassignableStates = [
            BookingStatus::Pending,
            BookingStatus::Confirmed,
            BookingStatus::OnHold,
            BookingStatus::CheckedIn,
        ];
        $canReassign = in_array($booking->booking_status, $reassignableStates, true)
            && $booking->room?->property_id !== null;
        $reassignCandidates = collect();
        if ($canReassign) {
            // CheckedIn guests: the original checkin_date is in the
            // past + those nights are already spent in the current
            // room. Requiring candidate rooms to be free for the
            // FULL original range would filter out any room that
            // had another guest earlier in the week, hiding the
            // reassign option even when plenty of rooms are free
            // going forward. Use max(today, checkin_date) so we
            // only check the REMAINING nights - which is exactly
            // what the guest is moving into.
            $today = Carbon::today()->startOfDay();
            $candidateCheckin = $booking->booking_status === BookingStatus::CheckedIn
                ? ($today->greaterThan($booking->checkin_date->copy()->startOfDay()) ? $today : $booking->checkin_date)
                : $booking->checkin_date;
            $reassignCandidates = $allocator->availableRoomsInProperty(
                propertyId: (int) $booking->room->property_id,
                checkin: $candidateCheckin,
                checkout: $booking->checkout_date,
                excludeRoomId: $booking->room_id,
            );
        }

        // Preview delta for the currently-selected candidate — pro-rata,
        // same formula ReassignRoom uses. Nights physically spent in
        // the old room stay at the old rate; only remaining nights swap
        // to the new rate. For not-yet-CheckedIn bookings, remaining
        // = the full stay.
        $previewDelta = null;
        $previewCandidate = null;
        if ($this->reassignTargetRoomId !== null) {
            $previewCandidate = $reassignCandidates->firstWhere('id', $this->reassignTargetRoomId);
            if ($previewCandidate instanceof Room && $booking->room !== null) {
                $today = Carbon::today();
                $nights = (int) $booking->nights;
                if ($booking->booking_status === BookingStatus::CheckedIn && $booking->checkin_date !== null) {
                    $stayed = max(0, (int) $booking->checkin_date->copy()->startOfDay()->diffInDays($today, absolute: false));
                    $remaining = max(0, $nights - min($nights, $stayed));
                } else {
                    $remaining = $nights;
                }
                $previewDelta = ((int) $previewCandidate->base_rate - (int) $booking->room->base_rate)
                    * $remaining;
            }
        }

        // Overstay preview, delegated to the shared Booking methods so
        // this stays in lockstep with the dashboard / finance receivable
        // rollups (they use the same formula) and with MarkCheckedOut.
        //
        // Three numbers because the auto-cron and the operator's manual
        // action interact — a partial catch-up leaves a gap the banner
        // must own up to instead of hiding behind "already charged":
        //
        //   $overstayNights   — total nights past booked checkout
        //   $overstayCharged  — nights already on the ledger (settled or not)
        //   $overstayAmount   — ₦ still uncharged (delta × base_rate)
        $today = Carbon::today();
        $overstayNights = $booking->projectedOverstayNights($today);
        $overstayCharged = $booking->overstayNightsCharged();
        $overstayAmount = $booking->projectedOverstayAmount($today);

        // Wallet balance for the linked customer, surfaces the
        // "Wallet" settlement option only when there's actually money
        // to spend. Falls back to 0 for walk-ins with no customer.
        $walletBalance = (int) ($booking->customer?->walletBalance() ?? 0);

        // Housekeeping gate — if there's an open inspection on this
        // booking's room, check-in will refuse (see MarkCheckedIn).
        // Front-desk + Manager get the override button so they can
        // clear the room and proceed themselves; anyone else has to
        // wait for the housekeeping chain to sign off.
        $openInspection = $booking->room !== null
            ? RoomInspection::query()
                ->where('room_id', $booking->room->id)
                ->whereNotIn('status', [
                    RoomInspectionStatus::Passed->value,
                    RoomInspectionStatus::Failed->value,
                ])
                ->latest('id')->first()
            : null;
        $operator = auth('web')->user();
        $canOverrideHousekeeping = $openInspection !== null
            && $operator instanceof Operator
            && in_array($operator->role, [OperatorRole::Frontdesk, OperatorRole::Manager], true);

        $timeline = $this->buildTimeline($booking);

        // Category-only (unassigned) booking: pull the free rooms in
        // the picked category + the alternate categories the operator
        // could switch to. Empty collections when the booking already
        // has a room pinned.
        $assignableRooms = collect();
        $switchableCategories = collect();
        if ($booking->room_id === null && $booking->room_category_id !== null) {
            $availability = app(AvailabilityService::class);
            $assignableRooms = Room::query()
                ->where('room_category_id', $booking->room_category_id)
                ->where('status', \App\Enums\RoomStatus::Active->value)
                ->orderBy('room_number')
                ->get()
                ->filter(fn (Room $r) => $availability->isAvailable(
                    $r, $booking->checkin_date, $booking->checkout_date,
                ))->values();
            $switchableCategories = RoomCategory::query()
                ->when($booking->room_category_id !== null,
                    fn ($q) => $q->where('id', '!=', $booking->room_category_id))
                ->orderBy('name')->get();
        }

        return view('livewire.dashboard.reservations.show', [
            'assignableRooms' => $assignableRooms,
            'switchableCategories' => $switchableCategories,
            'timeline' => $timeline,
            'booking' => $booking,
            'walletBalance' => $walletBalance,
            'charges' => $charges,
            'unsettledTotal' => $unsettledTotal,
            'settledTotal' => $settledTotal,
            'outstandingTotal' => $booking->outstandingTotal(),
            'chargeTypeOptions' => BookingChargeType::manualOptions(),
            'overstayNights' => $overstayNights,
            'overstayCharged' => $overstayCharged,
            'overstayAmount' => $overstayAmount,
            'openInspection' => $openInspection,
            'canOverrideHousekeeping' => $canOverrideHousekeeping,
            'canCheckIn' => in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true)
                && $booking->checkin_date->toDateString() === Carbon::today()->toDateString(),
            // Corp guarantor: skip the Confirm & take payment gate.
            // Front desk sees a direct "Check in (corp guaranteed)"
            // action while the booking is still Pending.
            'canCorporateCheckIn' => $booking->booking_status === BookingStatus::Pending
                && $booking->isCorporatePreAuthorized()
                && $booking->room_id !== null
                && $booking->checkin_date->toDateString() <= Carbon::today()->toDateString(),
            // Approval-gated check-in without payment: available on
            // Pending bookings that aren't corp-guaranteed AND don't
            // already have a pending approval sitting in the queue.
            'pendingCheckinApproval' => \App\Models\GuestActionApproval::query()
                ->where('booking_id', $booking->id)
                ->where('kind', 'checkin_no_payment')
                ->where('status', 'pending')
                ->latest('id')
                ->first(),
            'canRequestCheckinApproval' => $booking->booking_status === BookingStatus::Pending
                && ! $booking->isCorporatePreAuthorized()
                && $booking->room_id !== null,
            'canCheckOut' => $booking->booking_status === BookingStatus::CheckedIn,
            'canExtend' => in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn], true),
            'canNoShow' => in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::Pending], true)
                && Carbon::today()->gte($booking->checkin_date->copy()->startOfDay()),
            // Cancel is a pre-arrival action, once the guest is
            // physically CheckedIn we exit via Check out, not Cancel
            // (an already-in-house guest can't have their booking
            // "un-created", the room-nights + payments are already
            // real).
            'canCancel' => ! in_array($booking->booking_status, [
                BookingStatus::Cancelled,
                BookingStatus::CheckedIn,
                BookingStatus::CheckedOut,
                BookingStatus::NoShow,
            ], true),
            'canReassign' => $canReassign,
            'reassignCandidates' => $reassignCandidates,
            'previewDelta' => $previewDelta,
            'previewCandidate' => $previewCandidate,
        ]);
    }

    private function booking(): Booking
    {
        return Booking::query()
            ->with('room', 'customer', 'corporate', 'corporateMember', 'manualDiscountBy')
            ->findOrFail($this->bookingId);
    }

    /**
     * Common wrapper, runs the closure, catches operational exceptions,
     * surfaces them via $errorMessage. Keeps every action method tidy.
     */
    private function runActorAction(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    /**
     * Compose a chronological timeline of the booking's lifecycle
     * events so the front desk + accountant can see exactly WHEN
     * each thing happened. Prior to this the page rendered the
     * arrival / departure dates only, and every operator report we
     * got started with "when was this checked in?" - the data was
     * captured in activity_log all along, just never surfaced.
     *
     * @return list<array{label: string, at: \Illuminate\Support\Carbon, meta: ?string, kind: string}>
     */
    private function buildTimeline(Booking $booking): array
    {
        $events = \App\Models\Activity::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->orderBy('id')
            ->get(['id', 'event', 'properties', 'causer_id', 'causer_type', 'created_at']);

        $timeline = [];

        // Booking creation - always first entry, sourced from the
        // booking's own created_at (activity_log's `created` event
        // fires in the same tick but this is the authoritative one).
        $timeline[] = [
            'label' => 'Booked',
            'at' => $booking->created_at ?? Carbon::now(),
            'meta' => $booking->source?->label(),
            'kind' => 'created',
        ];

        foreach ($events as $ev) {
            $props = $ev->properties ?? collect();
            $causer = $ev->causer?->name;

            switch ((string) $ev->event) {
                case 'checked_in':
                    $timeline[] = [
                        'label' => 'Checked in',
                        'at' => $ev->created_at,
                        'meta' => $causer ? 'by '.$causer : null,
                        'kind' => 'checked_in',
                    ];
                    break;

                case 'checked_out':
                    $timeline[] = [
                        'label' => 'Checked out',
                        'at' => $ev->created_at,
                        'meta' => $causer ? 'by '.$causer : null,
                        'kind' => 'checked_out',
                    ];
                    break;

                case 'confirmed':
                    $timeline[] = [
                        'label' => 'Confirmed',
                        'at' => $ev->created_at,
                        'meta' => $causer ? 'by '.$causer : null,
                        'kind' => 'confirmed',
                    ];
                    break;

                case 'manual_payment_recorded':
                    $amt = is_array($props) ? ($props['amount'] ?? 0) : $props->get('amount', 0);
                    $method = is_array($props) ? ($props['method'] ?? '') : $props->get('method', '');
                    $timeline[] = [
                        'label' => 'Payment received',
                        'at' => $ev->created_at,
                        'meta' => sprintf(
                            '₦%s · %s%s',
                            number_format((int) $amt),
                            $method ?: 'off-platform',
                            $causer ? ' · by '.$causer : '',
                        ),
                        'kind' => 'paid',
                    ];
                    break;

                case 'part_payment_recorded':
                    $amt = is_array($props) ? ($props['amount'] ?? 0) : $props->get('amount', 0);
                    $method = is_array($props) ? ($props['method'] ?? '') : $props->get('method', '');
                    $timeline[] = [
                        'label' => 'Part payment',
                        'at' => $ev->created_at,
                        'meta' => sprintf(
                            '₦%s · %s%s',
                            number_format((int) $amt),
                            $method ?: 'off-platform',
                            $causer ? ' · by '.$causer : '',
                        ),
                        'kind' => 'paid',
                    ];
                    break;

                case 'extended':
                    $chargedProp = is_array($props) ? ($props['extension_charged'] ?? 0) : $props->get('extension_charged', 0);
                    $timeline[] = [
                        'label' => 'Extended',
                        'at' => $ev->created_at,
                        'meta' => sprintf(
                            '+₦%s%s',
                            number_format((int) $chargedProp),
                            $causer ? ' · by '.$causer : '',
                        ),
                        'kind' => 'extended',
                    ];
                    break;

                case 'extension_cancelled':
                    $voided = is_array($props) ? ($props['amount_voided'] ?? 0) : $props->get('amount_voided', 0);
                    $timeline[] = [
                        'label' => 'Extension cancelled',
                        'at' => $ev->created_at,
                        'meta' => sprintf(
                            '−₦%s%s',
                            number_format((int) $voided),
                            $causer ? ' · by '.$causer : '',
                        ),
                        'kind' => 'extension_cancelled',
                    ];
                    break;

                case 'cancelled':
                    $timeline[] = [
                        'label' => 'Cancelled',
                        'at' => $ev->created_at,
                        'meta' => $causer ? 'by '.$causer : null,
                        'kind' => 'cancelled',
                    ];
                    break;

                case 'no_show':
                    $timeline[] = [
                        'label' => 'Marked no-show',
                        'at' => $ev->created_at,
                        'meta' => $causer ? 'by '.$causer : null,
                        'kind' => 'no_show',
                    ];
                    break;
            }
        }

        // Keep it chronological, oldest → newest, mirroring how
        // operators read a lifecycle.
        usort($timeline, fn ($a, $b) => $a['at']->timestamp <=> $b['at']->timestamp);

        return $timeline;
    }
}
