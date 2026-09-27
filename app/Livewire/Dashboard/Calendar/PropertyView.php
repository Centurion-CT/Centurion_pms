<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Calendar;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Bookings\MarkNoShow;
use App\Actions\Bookings\PayBookingFromWallet;
use App\Actions\Bookings\ReassignRoom;
use App\Actions\Bookings\RecordManualPayment;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Livewire\Concerns\HasCalendarSearch;
use App\Livewire\Concerns\HasCustomerLookup;
use App\Livewire\Concerns\HasDiscountLookup;
use App\Livewire\Concerns\HasExtendStayModal;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\RoomAllocator;
use App\Services\Customers\CustomerLinker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

/**
 * Property-level monthly heat-map (PRD §6.2.3) + click/drag-to-book +
 * click-booked-cell-to-view.
 *
 * One row per Active room, one column per day. Cells render as
 * empty / blocked / booked. Cells are interactive:
 *
 *   - free cell, single click   → new-reservation sheet, 1 night
 *   - free cell, cursor drag    → new-reservation sheet, picked range
 *   - booked cell, single click → existing-reservation sheet with
 *                                  summary + check-in/out/no-show/cancel
 *
 * The drag interaction is implemented in vanilla JS on the view; it
 * dispatches an `open-reservation-sheet` or `open-booking-sheet`
 * Livewire event with the resolved payload which we catch via #[On].
 *
 * URL-bound `month` (YYYY-MM) so the operator can deep-link from the
 * dashboard or share with staff.
 */
#[Layout('layouts.tenant-auth')]
class PropertyView extends Component
{
    use HasCalendarSearch;
    use HasCustomerLookup;
    use HasDiscountLookup;
    use HasExtendStayModal;

    /**
     * Window length in days. 30 is a balance between "fits on a wide
     * screen at a useful column width" and "shows enough to plan
     * around the next month". Not URL-bound, the operator's screen
     * width dictates ideal width more than they need to share.
     */
    public const WINDOW_DAYS = 30;

    /**
     * Anchor for the rolling window, Y-m-d. Defaults to today; the
     * window covers [anchor, anchor + WINDOW_DAYS).
     *
     * Kept as a date string so it survives JSON serialisation across
     * Livewire wire calls without timezone drift.
     */
    #[Url(as: 'a')]
    public string $anchor = '';

    /**
     * When true, the window is extended backward to the first of the
     * current month so the operator can scroll through the past
     * activity. Off by default, most of the time the operator wants
     * to look ahead, not back.
     */
    #[Url(as: 'past')]
    public bool $showPast = false;

    // ── New-reservation sheet state ──────────────────────────────────
    public bool $sheetOpen = false;
    public ?int $sheetRoomId = null;
    public string $sheetRoomName = '';
    public string $sheetCheckin = '';
    public string $sheetCheckout = '';

    // Booking form fields for the new-reservation sheet
    #[Validate('required|string|max:120')]
    public string $guest_name = '';

    #[Validate('required|email|max:180')]
    public string $guest_email = '';

    #[Validate('required|string|max:32|regex:/^\+?[0-9\s\-()]{8,}$/')]
    public string $guest_phone = '';

    #[Validate('required|integer|min:1')]
    public int $adults = 1;

    #[Validate('required|integer|min:0')]
    public int $children = 0;

    #[Validate('nullable|string|max:1000')]
    public string $special_requests = '';

    /**
     * Cash-settlement choice; matches the ManualCreate contract so
     * frontdesk sees the same options no matter which surface they
     * create bookings from. `wallet` is only accepted when a matched
     * customer has wallet balance (guarded in saveReservation).
     */
    #[Validate('required|in:pending,succeeded,wallet')]
    public string $payment_settlement = 'succeeded';

    // Channel / OTA the booking came in on. Frontdesk picks one when
    // creating from the calendar; feeds reports + payments breakdowns.
    #[Validate('required|string')]
    public string $source = 'walk_in';

    #[Validate('nullable|string|max:120')]
    public string $source_note = '';

    // "With complimentary breakfast" opt-in, offered only when the
    // sheet's picked room's category has breakfast_included_rate set.
    #[Validate('boolean')]
    public bool $breakfast_included = false;

    // ── Post-create "Check in now?" prompt state ────────────────────
    // Same shape HasQuickReserveForm + ManualCreate carry. Populated
    // by finaliseCalendarReservationOutcome() when the just-created
    // booking is for today and can transition to CheckedIn.
    public ?string $postCreateBookingRef = null;

    public string $postCreateGuestName = '';

    public bool $postCreateShowCheckInPrompt = false;

    /**
     * Ad-hoc front-desk discount, entered as either a percent (0-100
     * fractional) or a flat ₦ amount. Both denormalise to a ₦ off on
     * the booking row for reports.
     */
    #[Validate('in:percent,fixed')]
    public string $manual_discount_type = 'percent';

    #[Validate('numeric|min:0|max:100')]
    public float $manual_discount_percent = 0.0;

    #[Validate('integer|min:0')]
    public int $manual_discount_fixed_input = 0;

    public function updatedManualDiscountPercent(): void
    {
        $this->manual_discount_percent = max(0.0, min(100.0, (float) $this->manual_discount_percent));
    }

    public function updatedManualDiscountFixedInput(): void
    {
        $this->manual_discount_fixed_input = max(0, (int) $this->manual_discount_fixed_input);
    }

    public function updatedManualDiscountType(): void
    {
        // Reset the OTHER mode's value so a stale entry doesn't
        // silently apply after flipping modes.
        if ($this->manual_discount_type === 'percent') {
            $this->manual_discount_fixed_input = 0;
        } else {
            $this->manual_discount_percent = 0.0;
        }
    }

    // ── View-existing-booking sheet state ───────────────────────────
    public bool $bookingSheetOpen = false;
    public ?int $viewBookingId = null;
    public bool $cancelRefund = false;
    public bool $noShowForfeit = true;

    // Room reassignment (same flow as the front-desk console, swaps
    // the booking to a different room in the SAME category).
    public bool $showReassign = false;
    public ?int $reassignTargetRoomId = null;

    // Drag-to-reassign preview + confirmation (a booking chip is dragged
    // from its current room row and dropped on another). We compute the
    // rate delta + feasibility here so the modal can render the exact
    // "same rate, no reconciliation needed" or "₦X more per night ×
    // Y remaining nights" copy the operator needs before committing.
    public bool $showReassignConfirm = false;
    public ?int $reassignBookingId = null;
    public ?int $reassignFromRoomId = null;
    public ?int $reassignToRoomId = null;
    public string $reassignFromRoomLabel = '';
    public string $reassignToRoomLabel = '';
    public string $reassignGuestSurname = '';
    public string $reassignCheckinLabel = '';
    public string $reassignCheckoutLabel = '';
    public int $reassignOldRate = 0;
    public int $reassignNewRate = 0;
    public int $reassignRateDelta = 0;
    public int $reassignRemainingNights = 0;
    public int $reassignNights = 0;
    public bool $reassignCrossCategory = false;
    public string $reassignBlocker = '';

    // Banners (shared across both sheets)
    public function mount(): void
    {
        if ($this->anchor === '') {
            $this->anchor = Carbon::today()->toDateString();
        }
        $this->initCalendarSearch();
    }

    public function previousWindow(): void
    {
        $this->anchor = Carbon::parse($this->anchor)
            ->subDays(self::WINDOW_DAYS)
            ->toDateString();
    }

    /**
     * Bound to wire:poll on the calendar grid. Re-runs render() so any
     * booking that landed (from the public booking funnel or another
     * operator) shows up on the calendar within the next tick, no
     * manual refresh needed.
     */
    public function refresh(): void
    {
        // wire:poll triggers the re-render; nothing else to do here.
    }

    public function nextWindow(): void
    {
        $this->anchor = Carbon::parse($this->anchor)
            ->addDays(self::WINDOW_DAYS)
            ->toDateString();
    }

    /**
     * Reset the window to today. Bound to the "Today" pill in the
     * date-nav so an operator that's scrolled away can snap back in
     * one click.
     */
    public function goToToday(): void
    {
        $this->anchor = Carbon::today()->toDateString();
        $this->showPast = false;
    }

    /**
     * Toggle past-day visibility. When on, the window anchor is
     * pulled back to the first of the current month so the operator
     * can scan recent history; off snaps back to today.
     */
    public function togglePast(): void
    {
        $this->showPast = ! $this->showPast;
        $this->anchor = $this->showPast
            ? Carbon::today()->startOfMonth()->toDateString()
            : Carbon::today()->toDateString();
    }

    // ─── New-reservation flow ────────────────────────────────────────

    #[On('open-reservation-sheet')]
    public function openSheet(int $roomId, string $checkin, string $checkout): void
    {
        // Use the operator's picked branch (matches the calendar's
        // own scoping below); falls back to first property for pre-
        // multi tenants exactly like PropertyContext does.
        $currentPropertyId = app(\App\Services\Properties\PropertyContext::class)->current()?->getKey();
        $room = Room::query()->where('property_id', $currentPropertyId)
            ->where('status', RoomStatus::Active->value)
            ->find($roomId);

        if ($room === null) {
            return;
        }

        try {
            $inDate = Carbon::parse($checkin)->startOfDay();
            $outDate = Carbon::parse($checkout)->startOfDay();
        } catch (Throwable) {
            return;
        }
        if ($outDate->lessThanOrEqualTo($inDate)) {
            $outDate = $inDate->copy()->addDay();
        }

        $this->resetForm();
        $this->bookingSheetOpen = false;
        $this->sheetRoomId = $room->id;
        $this->sheetRoomName = $room->name;
        $this->sheetCheckin = $inDate->toDateString();
        $this->sheetCheckout = $outDate->toDateString();
        $this->sheetOpen = true;
    }

    public function closeSheet(): void
    {
        $this->sheetOpen = false;
        $this->resetForm();
    }

    public function saveReservation(
        CreateBooking $create,
        RecordManualPayment $recordPayment,
        PayBookingFromWallet $walletPay,
    ): void {
        $this->validate();

        // Wallet payment prerequisites, matched customer with balance.
        if ($this->payment_settlement === 'wallet') {
            if ($this->selectedCustomerId === null) {
                throw ValidationException::withMessages([
                    'payment_settlement' => 'Pay from wallet needs an existing customer, pick one from the search.',
                ]);
            }
            if ($this->pickedCustomerWalletBalance <= 0) {
                throw ValidationException::withMessages([
                    'payment_settlement' => 'That customer has no wallet balance to spend.',
                ]);
            }
        }

        if ($this->sheetRoomId === null) {
            return;
        }
        $room = Room::query()->find($this->sheetRoomId);
        if ($room === null) {
            return;
        }

        $source = BookingSource::tryFrom($this->source) ?? BookingSource::WalkIn;
        $note = trim($this->source_note);

        $discountArgs = $this->discountSubmissionArgs();

        $req = new CreateBookingRequest(
            guestName: $this->guest_name,
            guestEmail: $this->guest_email,
            guestPhone: $this->guest_phone,
            adults: $this->adults,
            children: $this->children,
            checkin: Carbon::parse($this->sheetCheckin),
            checkout: Carbon::parse($this->sheetCheckout),
            paymentType: PaymentType::Full,
            specialRequests: $this->special_requests !== '' ? $this->special_requests : null,
            source: $source,
            sourceNote: $note !== '' ? $note : null,
            discountCode: $discountArgs['discountCode'],
            autoApplyDiscounts: $discountArgs['autoApplyDiscounts'],
            breakfastIncluded: $this->breakfast_included,
            manualDiscountType: $this->manual_discount_type === 'fixed'
                ? \App\Enums\ManualDiscountType::Fixed
                : \App\Enums\ManualDiscountType::Percent,
            manualDiscountPercent: (float) $this->manual_discount_percent,
            manualDiscountAmount: (int) $this->manual_discount_fixed_input,
            manualDiscountByOperatorId: auth('web')->id(),
        );

        try {
            $booking = $create->handle($room, $req);
        } catch (BookingValidationException $e) {
            throw ValidationException::withMessages(['sheetCheckin' => $e->getMessage()]);
        } catch (RangeNotAvailableException) {
            throw ValidationException::withMessages([
                'sheetCheckin' => __('Those dates collided with another booking, refresh and try again.'),
            ]);
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        // Wallet path, CreateBooking with paymentType=Full sets
        // balance_due=0 (assumes settled). Reset to full total so
        // PayBookingFromWallet actually charges, then flip booking
        // status based on how much cleared.
        if ($this->payment_settlement === 'wallet') {
            $booking->forceFill([
                'balance_due'    => (int) $booking->total_amount,
                'payment_status' => PaymentStatus::Pending,
            ])->save();

            try {
                $walletPay->handle($booking->refresh(), $operator);
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'payment_settlement' => 'Wallet charge failed: '.$e->getMessage(),
                ]);
            }
            $booking->refresh();
            $fullyPaid = $booking->outstandingTotal() <= 0;
            $booking->forceFill([
                'booking_status' => $fullyPaid ? BookingStatus::Confirmed : BookingStatus::OnHold,
            ])->save();

            $this->finaliseCalendarReservationOutcome($booking);

            return;
        }

        $isPaid = $this->payment_settlement === 'succeeded';
        $booking->forceFill([
            'booking_status' => $isPaid ? BookingStatus::Confirmed : BookingStatus::Pending,
            'payment_status' => $isPaid ? PaymentStatus::Succeeded : PaymentStatus::Pending,
        ])->save();

        // Log the receipt on the Payment ledger so Finance + Reports see
        // the cash. No-op when the operator marked the booking unpaid.
        if ($isPaid) {
            $recordPayment->handle($booking->refresh(), $operator);
        }

        $this->finaliseCalendarReservationOutcome($booking);
    }

    /**
     * Post-create branch: same shape HasQuickReserveForm +
     * ManualCreate carry — same-day + Confirmed/OnHold surfaces the
     * "Check in now?" modal; everything else redirects straight to
     * the booking-show page.
     */
    private function finaliseCalendarReservationOutcome(Booking $booking): void
    {
        $canPromptCheckIn = $booking->checkin_date->isSameDay(Carbon::today())
            && in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true);

        if ($canPromptCheckIn) {
            $this->postCreateBookingRef = $booking->booking_ref;
            $this->postCreateGuestName = $booking->guest_name;
            $this->postCreateShowCheckInPrompt = true;
            $this->dispatch('checkin-prompt-open');

            return;
        }

        $this->redirect(route('tenant.bookings.show', $booking->booking_ref), navigate: true);
    }

    public function confirmPostCreateCheckIn(\App\Actions\Bookings\MarkCheckedIn $action): void
    {
        if ($this->postCreateBookingRef === null) {
            return;
        }

        $booking = Booking::query()->where('booking_ref', $this->postCreateBookingRef)->first();
        if ($booking === null) {
            $this->skipPostCreateCheckIn();

            return;
        }

        $op = auth('web')->user();
        if ($op instanceof Operator) {
            try {
                $action->handle($booking, $op);
                if ($action->pendingInspection !== null) {
                    session()->flash('status', sprintf(
                        'Checked in %s — room needs cleaning attention (waiting on %s).',
                        $booking->guest_name,
                        $action->pendingInspection->status->waitingOn(),
                    ));
                } else {
                    session()->flash('status', "Checked in {$booking->guest_name}.");
                }
            } catch (Throwable $e) {
                session()->flash('status', 'Check-in failed: '.$e->getMessage());
            }
        }

        $ref = $this->postCreateBookingRef;
        $this->postCreateBookingRef = null;
        $this->postCreateGuestName = '';
        $this->postCreateShowCheckInPrompt = false;
        $this->redirect(route('tenant.bookings.show', $ref), navigate: true);
    }

    public function skipPostCreateCheckIn(): void
    {
        $ref = $this->postCreateBookingRef;
        if ($ref === null) {
            return;
        }
        $this->postCreateBookingRef = null;
        $this->postCreateGuestName = '';
        $this->postCreateShowCheckInPrompt = false;
        $this->redirect(route('tenant.bookings.show', $ref), navigate: true);
    }

    // ─── View-existing-booking flow ──────────────────────────────────

    #[On('open-booking-sheet')]
    public function openBookingSheet(int $bookingId): void
    {
        $booking = Booking::query()->find($bookingId);
        if ($booking === null) {
            return;
        }
        $this->sheetOpen = false;
        $this->viewBookingId = $booking->id;
        $this->cancelRefund = false;
        $this->noShowForfeit = true;
        $this->showReassign = false;
        $this->reassignTargetRoomId = null;
        $this->bookingSheetOpen = true;
    }

    public function closeBookingSheet(): void
    {
        $this->bookingSheetOpen = false;
        $this->viewBookingId = null;
        $this->showReassign = false;
        $this->reassignTargetRoomId = null;
    }

    public function toggleReassign(): void
    {
        $this->showReassign = ! $this->showReassign;
        $this->reassignTargetRoomId = null;
    }

    public function reassignRoom(ReassignRoom $action, CustomerLinker $linker): void
    {
        if ($this->reassignTargetRoomId === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Pick a room to swap to first.', kind: 'err');

            return;
        }
        $this->runBookingAction(function () use ($action, $linker): void {
            $booking = $this->viewedBooking();
            $newRoom = Room::query()->findOrFail($this->reassignTargetRoomId);
            $oldRoomNumber = $booking->room?->room_number ?? '-';

            try {
                $action->handle($booking, $newRoom, auth('web')->user());
            } catch (RangeNotAvailableException) {
                throw new \RuntimeException('That room was just taken, pick another.');
            }

            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.room_reassigned',
                subject: 'Room changed from '.$oldRoomNumber.' to '.($newRoom->room_number ?? '-'),
                body: null,
            );

            $this->showReassign = false;
            $this->reassignTargetRoomId = null;
            $this->dispatch('notify', title: 'Done', message: 'Reassigned to room '.($newRoom->room_number ?? '-').'.', kind: 'ok');
        });
    }

    /**
     * Server-side preview for the drag-to-reassign flow. Called when
     * the operator drops a booking chip on a different room row: we
     * re-check feasibility (state, room active, availability) and
     * compute the rate delta so the confirm modal renders the exact
     * reconciliation copy before we mutate any rows.
     */
    public function openReassignConfirm(
        int $bookingId,
        int $targetRoomId,
        AvailabilityService $svc,
    ): void {
        $this->resetReassignConfirm();

        $booking = Booking::query()->with('room')->find($bookingId);
        if ($booking === null || $booking->room === null) {
            $this->dispatch('notify', title: 'Couldn\'t start swap', message: 'Booking not found.', kind: 'err');

            return;
        }

        $legal = [
            BookingStatus::Pending,
            BookingStatus::Confirmed,
            BookingStatus::OnHold,
            BookingStatus::CheckedIn,
        ];
        if (! in_array($booking->booking_status, $legal, true)) {
            $this->dispatch('notify', title: 'Not swappable', message: 'Booking is '.$booking->booking_status->value.', past the point where a room swap makes sense.', kind: 'err');

            return;
        }

        if ($targetRoomId === $booking->room_id) {
            $this->dispatch('notify', title: 'Same room', message: 'Drop on a different room to swap.', kind: 'err');

            return;
        }

        $newRoom = Room::query()->find($targetRoomId);
        if ($newRoom === null) {
            $this->dispatch('notify', title: 'Couldn\'t start swap', message: 'Target room not found.', kind: 'err');

            return;
        }

        // Same-property guard (a drag can only travel within the
        // currently rendered heat-map so this should already hold,
        // but the client-side coord math is not authoritative).
        if ((int) $newRoom->property_id !== (int) $booking->room->property_id) {
            $this->dispatch('notify', title: 'Cross-property', message: 'Swap to a different property is not supported here.', kind: 'err');

            return;
        }

        if ($newRoom->status !== RoomStatus::Active) {
            $this->reassignBlocker = 'Target room is not active.';
        }

        $checkin = $booking->checkin_date->copy();
        $checkout = $booking->checkout_date->copy();
        $isCheckedIn = $booking->booking_status === BookingStatus::CheckedIn;

        // For CheckedIn bookings only the remaining nights swap over,
        // so availability + preview both look at "today onwards" only.
        $today = Carbon::today()->startOfDay();
        $effectiveCheckin = $isCheckedIn
            ? ($today->greaterThan($checkin->copy()->startOfDay()) ? $today : $checkin->copy())
            : $checkin;

        $oldRate = (int) $booking->room->base_rate;
        $newRate = (int) $newRoom->base_rate;
        $nights = (int) $booking->nights;
        $nightsStayed = $isCheckedIn
            ? min($nights, max(0, (int) $checkin->copy()->startOfDay()->diffInDays($today, absolute: false)))
            : 0;
        $remainingNights = max(0, $nights - $nightsStayed);

        // Overdue guest — checkout is already in the past + all nights
        // have been slept. There's no future range to reassign so the
        // action would resolve to a no-op at best, or throw at worst.
        // Tell the operator to extend or check out first; the modal
        // still opens so they see WHY the drag was refused.
        $isOverdue = $effectiveCheckin->greaterThanOrEqualTo($checkout);
        if ($isOverdue) {
            $this->reassignBlocker = 'Guest is past checkout with no remaining nights. Extend the stay or check them out before swapping rooms.';
        } elseif ($newRoom->status !== RoomStatus::Active) {
            // (already set above, but keep the order explicit so the
            // most-important blocker wins).
        } elseif (! $svc->isAvailable($newRoom, $effectiveCheckin, $checkout)) {
            $this->reassignBlocker = 'Target room already has another booking in that range.';
        }

        $this->showReassignConfirm = true;
        $this->reassignBookingId = $booking->id;
        $this->reassignFromRoomId = (int) $booking->room_id;
        $this->reassignToRoomId = $newRoom->id;
        $this->reassignFromRoomLabel = (string) ($booking->room->room_number ?? $booking->room->id);
        $this->reassignToRoomLabel = (string) ($newRoom->room_number ?? $newRoom->id);
        $this->reassignGuestSurname = (string) ($booking->guest_name ?? 'guest');
        // Show the booking's real range (not the CheckedIn-clipped
        // effective range) so the label always reads left-to-right
        // and matches what the operator sees on the reservation card.
        $this->reassignCheckinLabel = $checkin->format('D, j M');
        $this->reassignCheckoutLabel = $checkout->format('D, j M');
        $this->reassignOldRate = $oldRate;
        $this->reassignNewRate = $newRate;
        $this->reassignRateDelta = ($newRate - $oldRate) * $remainingNights;
        $this->reassignRemainingNights = $remainingNights;
        $this->reassignNights = $nights;
        $this->reassignCrossCategory = (int) ($newRoom->room_category_id ?? 0)
            !== (int) ($booking->room_category_id ?? 0);
    }

    public function cancelReassignConfirm(): void
    {
        $this->resetReassignConfirm();
    }

    /**
     * Commit the drag-to-reassign swap. Re-validates on the server
     * (the confirm modal could have been sitting open for a while
     * during which another operator grabbed the target room) so
     * we always see the freshest availability picture.
     */
    public function confirmReassignFromDrag(ReassignRoom $action, CustomerLinker $linker): void
    {
        if ($this->reassignBookingId === null || $this->reassignToRoomId === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Reassignment state was cleared, drop again.', kind: 'err');

            return;
        }

        // Preview flagged a hard blocker (overdue, inactive room,
        // target-taken). Refuse to commit — the modal already tells
        // the operator why. This is a defence-in-depth guard: the
        // Move-guest button is `disabled` in that state, but a stray
        // wire call could still hit us.
        if ($this->reassignBlocker !== '') {
            $this->dispatch('notify', title: 'Blocked', message: $this->reassignBlocker, kind: 'err');

            return;
        }

        $bookingId = $this->reassignBookingId;
        $targetRoomId = $this->reassignToRoomId;

        $this->runBookingAction(function () use ($action, $linker, $bookingId, $targetRoomId): void {
            $booking = Booking::query()->with('room')->findOrFail($bookingId);
            $newRoom = Room::query()->findOrFail($targetRoomId);
            $oldRoomNumber = $booking->room?->room_number ?? '-';

            try {
                $action->handle($booking, $newRoom, auth('web')->user());
            } catch (RangeNotAvailableException) {
                throw new \RuntimeException('That room was just taken, drop again on a different one.');
            }

            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.room_reassigned',
                subject: 'Room changed from '.$oldRoomNumber.' to '.($newRoom->room_number ?? '-'),
                body: null,
            );

            $this->resetReassignConfirm();
            $this->dispatch('notify', title: 'Done', message: 'Moved to room '.($newRoom->room_number ?? '-').'.', kind: 'ok');
        });
    }

    private function resetReassignConfirm(): void
    {
        $this->showReassignConfirm = false;
        $this->reassignBookingId = null;
        $this->reassignFromRoomId = null;
        $this->reassignToRoomId = null;
        $this->reassignFromRoomLabel = '';
        $this->reassignToRoomLabel = '';
        $this->reassignGuestSurname = '';
        $this->reassignCheckinLabel = '';
        $this->reassignCheckoutLabel = '';
        $this->reassignOldRate = 0;
        $this->reassignNewRate = 0;
        $this->reassignRateDelta = 0;
        $this->reassignRemainingNights = 0;
        $this->reassignNights = 0;
        $this->reassignCrossCategory = false;
        $this->reassignBlocker = '';
    }

    public function checkIn(MarkCheckedIn $action, CustomerLinker $linker): void
    {
        $this->runBookingAction(function () use ($action, $linker): void {
            $booking = $this->viewedBooking();
            $operator = auth('web')->user();
            $action->handle($booking, $operator);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.checked_in',
                subject: $booking->guest_name.' checked in',
                body: 'Room '.$booking->room?->name,
            );
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

    public function checkOut(MarkCheckedOut $action, CustomerLinker $linker): void
    {
        $this->runBookingAction(function () use ($action, $linker): void {
            $booking = $this->viewedBooking();
            if ($booking->outstandingTotal() > 0) {
                throw new \RuntimeException(
                    'Outstanding balance of ₦'.number_format($booking->outstandingTotal())
                    .' must be cleared before check-out.'
                );
            }
            $operator = auth('web')->user();
            $action->handle($booking, $operator);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.checked_out',
                subject: $booking->guest_name.' checked out',
                body: null,
            );
            $this->dispatch('notify', title: 'Done', message: 'Checked out, calendar refreshed.', kind: 'ok');
        });
    }

    public function markNoShow(MarkNoShow $action, CustomerLinker $linker): void
    {
        $this->runBookingAction(function () use ($action, $linker): void {
            $booking = $this->viewedBooking();
            $operator = auth('web')->user();
            $action->handle($booking, $operator, $this->noShowForfeit);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.no_show',
                subject: $booking->guest_name.' marked no-show',
                body: $this->noShowForfeit && (int) $booking->deposit_amount > 0
                    ? 'Deposit ₦'.number_format((int) $booking->deposit_amount).' forfeit'
                    : null,
            );
            $this->dispatch('notify', title: 'Done', message: 'Marked no-show, room re-entered inventory.', kind: 'ok');
        });
    }

    public function cancelBooking(CancelBooking $action, CustomerLinker $linker): void
    {
        $this->runBookingAction(function () use ($action, $linker): void {
            $booking = $this->viewedBooking();
            $operator = auth('web')->user();
            $action->handle($booking, $operator, $this->cancelRefund);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.cancelled',
                subject: 'Booking '.$booking->booking_ref.' cancelled',
                body: $this->cancelRefund ? 'Refund requested' : 'No refund requested',
            );
            $this->dispatch('notify', title: 'Done', message: 'Reservation cancelled, nights released.', kind: 'ok');
        });
    }

    private function resetForm(): void
    {
        $this->guest_name = '';
        $this->guest_email = '';
        $this->guest_phone = '';
        $this->adults = 1;
        $this->children = 0;
        $this->special_requests = '';
        $this->payment_settlement = 'succeeded';
        $this->source = BookingSource::WalkIn->value;
        $this->source_note = '';
        $this->breakfast_included = false;
        $this->customerSearch = '';
        $this->selectedCustomerId = null;
        $this->clearDiscount();
        $this->resetErrorBag();
    }

    protected function discountContext(): ?array
    {
        if (! $this->sheetOpen || $this->sheetRoomId === null
            || $this->sheetCheckin === '' || $this->sheetCheckout === ''
        ) {
            return null;
        }
        $room = Room::query()->find($this->sheetRoomId);
        if ($room === null) {
            return null;
        }
        try {
            $checkin = Carbon::parse($this->sheetCheckin);
            $checkout = Carbon::parse($this->sheetCheckout);
        } catch (Throwable) {
            return null;
        }
        if ($checkout->lessThanOrEqualTo($checkin)) {
            return null;
        }
        try {
            $preview = app(\App\Services\Pricing\PricingCalculator::class)
                ->calculate($room, $checkin, $checkout);
        } catch (Throwable) {
            return null;
        }

        return [
            'room' => $room,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'channel' => BookingSource::tryFrom($this->source) ?? BookingSource::WalkIn,
            'netSubtotal' => $preview->netSubtotal,
        ];
    }

    private function runBookingAction(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    private function viewedBooking(): Booking
    {
        return Booking::query()->with('room', 'customer')->findOrFail($this->viewBookingId);
    }

    public function render(AvailabilityService $svc, RoomAllocator $allocator): View
    {
        // Keep auto-apply / manual discount in sync with the sheet's
        // current room + date selection. Empty when sheet is closed.
        if ($this->sheetOpen) {
            $this->refreshDiscount();
        }

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        // Show the calendar for the branch the operator is working in
        // — falls back to first property for pre-multi tenants so the
        // single-property calendar keeps rendering exactly as before.
        $property = app(\App\Services\Properties\PropertyContext::class)->current()
            ?? $tenant->property;

        $windowStart = Carbon::parse($this->anchor)->startOfDay();
        $windowEnd   = $windowStart->copy()->addDays(self::WINDOW_DAYS - 1);

        // Build the day list once, the view loops over this for both
        // the header and each room row. Each entry is a Carbon so the
        // template can show day number + month-change indicators.
        $days = [];
        for ($cursor = $windowStart->copy(); $cursor->lte($windowEnd); $cursor->addDay()) {
            $days[] = $cursor->copy();
        }

        $rooms = $property === null
            ? collect()
            : $property->rooms()
                ->with('category')
                ->where('status', RoomStatus::Active->value)
                ->orderBy('room_category_id')
                ->orderBy('room_number')
                ->get();

        // Matrix indexed by Y-m-d so it copes with windows that span
        // multiple months (May 25 → Jun 23 has Jun 1's index collide
        // with May 1's under the old day-number scheme).
        //
        //   $matrix[$roomId]['2026-05-25'] = 'booked'|'pending'|'blocked'|null
        //   $bookingByCell[$roomId]['2026-05-25'] = booking_id (when booked/pending)
        $matrix = [];
        $bookingByCell = [];
        $bookingIdsTouched = [];
        foreach ($rooms as $room) {
            $matrix[$room->id] = [];
            $bookingByCell[$room->id] = [];
            foreach ($days as $d) {
                $matrix[$room->id][$d->toDateString()] = null;
            }
            foreach ($svc->getBlocksForRange($room, $windowStart, $windowEnd) as $b) {
                $key = $b->date->toDateString();
                $matrix[$room->id][$key] = $b->type->value;
                if ($b->booking_id !== null) {
                    $bookingByCell[$room->id][$key] = $b->booking_id;
                    $bookingIdsTouched[$b->booking_id] = true;
                }
            }
        }

        // Distinguish Pending bookings (sitting on a hold while the
        // guest is mid-checkout) from fully Confirmed/CheckedIn/etc.
        // A second pass overwrites 'booked' with 'pending' for cells
        // whose underlying booking is still Pending. The cron sweeps
        // those after 15 minutes, but for the operator the visual
        // difference matters NOW, "hold" vs "real sale".
        if ($bookingIdsTouched !== []) {
            $pendingIds = Booking::query()
                ->whereIn('id', array_keys($bookingIdsTouched))
                ->where('booking_status', BookingStatus::Pending->value)
                ->pluck('id')
                ->all();
            $pendingIds = array_fill_keys($pendingIds, true);

            foreach ($bookingByCell as $roomId => $cells) {
                foreach ($cells as $date => $bookingId) {
                    if (isset($pendingIds[$bookingId])) {
                        $matrix[$roomId][$date] = 'pending';
                    }
                }
            }

            // Third pass, currently CheckedIn bookings become
            // checkedin / due_out / overdue based on today vs the
            // booking's checkout_date. Shows the room's live state at
            // a glance: blue in-house, yellow due out today, red
            // overdue (guest hasn't been checked out yet).
            $checkedIn = Booking::query()
                ->whereIn('id', array_keys($bookingIdsTouched))
                ->where('booking_status', BookingStatus::CheckedIn->value)
                ->get(['id', 'checkout_date'])
                ->keyBy('id');

            if ($checkedIn->isNotEmpty()) {
                $todayIso = Carbon::today()->toDateString();
                foreach ($bookingByCell as $roomId => $cells) {
                    foreach ($cells as $date => $bookingId) {
                        $booking = $checkedIn->get($bookingId);
                        if ($booking === null) {
                            continue;
                        }
                        $checkoutIso = $booking->checkout_date?->toDateString();
                        if ($checkoutIso === null) {
                            continue;
                        }
                        if ($todayIso > $checkoutIso) {
                            $matrix[$roomId][$date] = 'overdue';
                        } elseif ($todayIso === $checkoutIso) {
                            $matrix[$roomId][$date] = 'due_out';
                        } else {
                            $matrix[$roomId][$date] = 'checkedin';
                        }
                    }
                }
            }
        }

        // Fourth pass, checkout-day + post-checkout overlay for
        // CheckedIn guests. AvailabilityBlocks span [checkin, checkout),
        // so the checkout day itself has no block, but the guest is
        // still physically in the room until MarkCheckedOut runs.
        // Without this overlay the checkout day paints as free and the
        // operator could reserve on top of the current occupant.
        //
        // Overlay rules (only for cells inside the visible window that
        // don't already have a block):
        //   - checkout_date == today → 'due_out'
        //   - checkout_date <  today → 'overdue' for every day from
        //     checkout_date through today (guest overstayed + wasn't
        //     checked out).
        $activeCheckins = Booking::query()
            ->whereIn('room_id', $rooms->pluck('id')->all())
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereNotNull('checkout_date')
            ->whereDate('checkout_date', '<=', Carbon::today()->toDateString())
            ->get(['id', 'room_id', 'checkout_date']);

        if ($activeCheckins->isNotEmpty()) {
            $today = Carbon::today();
            foreach ($activeCheckins as $booking) {
                $checkoutDate = $booking->checkout_date->copy()->startOfDay();
                $overlayStart = $checkoutDate->greaterThan($windowStart) ? $checkoutDate : $windowStart->copy();
                $overlayEnd   = $today->lessThan($windowEnd) ? $today : $windowEnd->copy();

                for ($c = $overlayStart->copy(); $c->lessThanOrEqualTo($overlayEnd); $c->addDay()) {
                    $iso = $c->toDateString();
                    // array_key_exists, free cells are initialised
                    // to null, and isset() would falsely skip them.
                    if (! array_key_exists($iso, $matrix[$booking->room_id] ?? [])) {
                        continue;   // room dropped out of visible set
                    }
                    // Don't stomp on a real block, a new arrival for
                    // today (already 'booked'/'checkedin') wins.
                    if ($matrix[$booking->room_id][$iso] !== null) {
                        continue;
                    }
                    $matrix[$booking->room_id][$iso] = $iso === $today->toDateString()
                        ? ($checkoutDate->lessThan($today) ? 'overdue' : 'due_out')
                        : 'overdue';
                    $bookingByCell[$booking->room_id][$iso] = $booking->id;
                }
            }
        }

        // Chip metadata. For every visible cell tied to a booking, mark
        // its position within the contiguous run of same-booking cells
        // so the blade can paint a spanning bar with the guest surname
        // on the START cell only. Empty cells + solo bookings still work
        // ('single' position renders the surname without a continuation).
        //
        //   $chipMeta[$roomId][$isoDate] = [
        //       'booking_id' => 12,
        //       'position'   => 'start' | 'middle' | 'end' | 'single',
        //       'surname'    => 'Adaeze',
        //   ]
        $chipMeta = [];
        if ($bookingByCell !== []) {
            $touchedBookingIds = [];
            foreach ($bookingByCell as $cells) {
                foreach ($cells as $bookingId) {
                    $touchedBookingIds[$bookingId] = true;
                }
            }
            $bookingRows = Booking::query()
                ->whereIn('id', array_keys($touchedBookingIds))
                ->get(['id', 'guest_name', 'checkout_date']);
            $guestNames = $bookingRows->pluck('guest_name', 'id')->all();
            // ISO checkout date per booking so the chip drag knows
            // where the booking's trailing edge sits in date-space -
            // the JS uses this to decide "drag past the end == extend".
            $checkoutIso = $bookingRows->mapWithKeys(fn ($b) => [
                $b->id => $b->checkout_date instanceof \Illuminate\Support\Carbon
                    ? $b->checkout_date->toDateString()
                    : (string) $b->checkout_date,
            ])->all();

            // $days can be a Collection (production render path) or a plain
            // array in some test scenarios; normalise before mapping.
            $daysList = is_array($days) ? $days : $days->all();
            $isoDates = array_map(fn ($d) => $d->toDateString(), $daysList);
            foreach ($bookingByCell as $roomId => $cellMap) {
                $chipMeta[$roomId] = [];
                $prevBookingId = null;
                $runStartDate = null;
                $runDates = [];
                $emitRun = function () use (&$chipMeta, &$roomId, &$prevBookingId, &$runDates, $guestNames, $checkoutIso): void {
                    if ($prevBookingId === null || $runDates === []) {
                        return;
                    }
                    $fullName = $guestNames[$prevBookingId] ?? '';
                    // Prefer the last word of the guest name as the
                    // surname; fall back to the first word for a
                    // single-name guest so the chip still labels itself.
                    $parts = preg_split('/\s+/', trim($fullName)) ?: [''];
                    $surname = trim(end($parts) ?: reset($parts) ?: '') ?: 'Guest';
                    $bookingCheckout = $checkoutIso[$prevBookingId] ?? '';
                    // ISO of the LAST cell in this row's contiguous run
                    // for this booking. The chip drag uses this as its
                    // reference point (walk-start = this cell + 1) so
                    // extend still works when the booking's real
                    // checkout is off-window - e.g. an overdue guest
                    // whose chip is drawn on today but whose recorded
                    // checkout is in the past.
                    $lastRunIso = (string) end($runDates);
                    reset($runDates);
                    $count = count($runDates);
                    foreach ($runDates as $i => $date) {
                        $position = match (true) {
                            $count === 1                  => 'single',
                            $i === 0                      => 'start',
                            $i === $count - 1             => 'end',
                            default                       => 'middle',
                        };
                        $chipMeta[$roomId][$date] = [
                            'booking_id'  => $prevBookingId,
                            'position'    => $position,
                            'surname'     => $surname,
                            'checkout_iso' => $bookingCheckout,
                            'last_iso'    => $lastRunIso,
                        ];
                    }
                };
                foreach ($isoDates as $iso) {
                    $bookingId = $cellMap[$iso] ?? null;
                    if ($bookingId !== $prevBookingId) {
                        // Flush the previous run before starting a new one.
                        $emitRun();
                        $prevBookingId = $bookingId;
                        $runDates = [];
                    }
                    if ($bookingId !== null) {
                        $runDates[] = $iso;
                    }
                }
                $emitRun();
            }
        }

        // Quick-search: hide rooms that don't match the operator's
        // room-number / availability filter. Total count is preserved
        // for the "Showing X of Y" hint above the grid.
        $totalRooms = $rooms->count();
        $allowedIds = $this->calendarSearchAllowedRoomIds($rooms);
        $visibleRooms = $rooms->filter(fn ($r) => isset($allowedIds[$r->id]))->values();

        // Group rooms by their category for the section-header layout.
        // Each entry: { category, rooms: [...] }. Rooms without a
        // category fall under an "Uncategorised" pseudo-entry so
        // legacy / partially-migrated rows still render.
        $grouped = $visibleRooms
            ->groupBy(fn ($r) => $r->room_category_id ?? 0)
            ->map(function ($group) {
                $cat = $group->first()?->category;

                return (object) [
                    'category' => $cat,
                    'name' => $cat?->name ?? 'Uncategorised',
                    'type' => $cat?->type,
                    'rooms' => $group->values(),
                ];
            })
            ->values();

        // Resolve the viewed booking + its derived state, only when the
        // booking-info sheet is open. Keeps the cheap monthly heat-map
        // render path untouched the other 99% of the time.
        $viewed = null;
        $viewedDerived = [
            'statusLabel' => '',
            'statusClass' => '',
            'unsettledTotal' => 0,
            'outstandingTotal' => 0,
            'canCheckIn' => false,
            'canCheckOut' => false,
            'canNoShow' => false,
            'canCancel' => false,
            'canReassign' => false,
            'overstayNights' => 0,
            'overstayAmount' => 0,
            'overstayAlreadyCharged' => false,
        ];
        if ($this->bookingSheetOpen && $this->viewBookingId !== null) {
            $viewed = Booking::query()
                ->with(['room', 'customer', 'charges'])
                ->find($this->viewBookingId);
            if ($viewed !== null) {
                $statusValue = $viewed->booking_status->value;
                $viewedDerived['statusLabel'] = ucwords(str_replace('_', ' ', $statusValue));
                $viewedDerived['statusClass'] = match ($statusValue) {
                    'confirmed'   => 'lp-tag--confirmed',
                    'on_hold'     => 'lp-tag--on-hold',
                    'pending'     => 'lp-tag--pending',
                    'cancelled'   => 'lp-tag--cancelled',
                    'checked_in'  => 'lp-tag--checked-in',
                    'checked_out' => 'lp-tag--checked-out',
                    'no_show'     => 'lp-tag--cancelled',
                    default       => '',
                };
                $viewedDerived['unsettledTotal'] = (int) $viewed->charges->where('settled', false)->sum('amount');
                $viewedDerived['outstandingTotal'] = $viewed->outstandingTotal();
                $today = Carbon::today();
                $checkinDate = $viewed->checkin_date->copy()->startOfDay();
                $viewedDerived['canCheckIn'] = in_array($viewed->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true)
                    && $checkinDate->toDateString() === $today->toDateString();
                $viewedDerived['canCheckOut'] = $viewed->booking_status === BookingStatus::CheckedIn;
                // Extend stay covers the same pre-arrival + in-house
                // window as the reservation-show page - the shared
                // extend modal handles the rest of the guards.
                $viewedDerived['canExtend'] = in_array($viewed->booking_status, [
                    BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn,
                ], true);
                $viewedDerived['canNoShow'] = in_array($viewed->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::Pending], true)
                    && $today->gte($checkinDate);
                $viewedDerived['canCancel'] = ! in_array($viewed->booking_status, [
                    BookingStatus::Cancelled,
                    BookingStatus::CheckedIn,
                    BookingStatus::CheckedOut,
                    BookingStatus::NoShow,
                ], true);
                $viewedDerived['canReassign'] = in_array($viewed->booking_status, [
                    BookingStatus::Pending,
                    BookingStatus::Confirmed,
                    BookingStatus::OnHold,
                    BookingStatus::CheckedIn,
                ], true) && $viewed->room?->category !== null;

                // Overstay preview, when the guest is past their booked
                // checkout, surface the fee that the next checkout press
                // will auto-add (idempotent, only meaningful when not
                // already charged).
                $checkoutDate = $viewed->checkout_date->copy()->startOfDay();
                if ($today->greaterThan($checkoutDate)) {
                    $overstayNights = (int) $checkoutDate->diffInDays($today, absolute: true);
                    $baseRate = (int) ($viewed->room?->base_rate ?? 0);
                    $viewedDerived['overstayNights'] = $overstayNights;
                    $viewedDerived['overstayAmount'] = $baseRate * $overstayNights;
                    $viewedDerived['overstayAlreadyCharged'] = $viewed->charges
                        ->where('type', \App\Enums\BookingChargeType::Overstay)
                        ->isNotEmpty();
                }
            }
        }

        // Reassign candidates - cross-category, same constraint set
        // as the reservations console. For CheckedIn guests, use
        // max(today, checkin_date) as the effective check window
        // so past-night conflicts on the target room don't drop it
        // out of the pool (the guest only moves into the remaining
        // nights). Previously scoped to the current category only,
        // which hid every cross-category swap - now uses
        // availableRoomsInProperty so the operator sees every free
        // room across the property.
        $reassignCandidates = collect();
        if ($viewed !== null && ($viewedDerived['canReassign'] ?? false) && $viewed->room?->property_id !== null) {
            $today = Carbon::today()->startOfDay();
            $candidateCheckin = $viewed->booking_status === BookingStatus::CheckedIn
                ? ($today->greaterThan($viewed->checkin_date->copy()->startOfDay()) ? $today : $viewed->checkin_date)
                : $viewed->checkin_date;
            $reassignCandidates = $allocator->availableRoomsInProperty(
                propertyId: (int) $viewed->room->property_id,
                checkin: $candidateCheckin,
                checkout: $viewed->checkout_date,
                excludeRoomId: $viewed->room_id,
            );
        }

        // The picked room in the new-reservation sheet, its category
        // decides whether to render the "with breakfast" radio pair.
        $sheetRoom = $this->sheetRoomId !== null
            ? $rooms->firstWhere('id', $this->sheetRoomId)
            : null;
        if ($sheetRoom !== null && ! $sheetRoom->category?->hasBreakfastOption()) {
            $this->breakfast_included = false;
        }

        return view('livewire.dashboard.calendar.property-view', [
            'rooms' => $visibleRooms,
            'matrix' => $matrix,
            'bookingByCell' => $bookingByCell,
            'chipMeta' => $chipMeta,
            'grouped' => $grouped,
            'days' => $days,
            'windowStart' => $windowStart,
            'windowEnd' => $windowEnd,
            'visibleRoomCount' => $visibleRooms->count(),
            'totalRoomCount' => $totalRooms,
            'sheetRoom' => $sheetRoom,
            'viewed' => $viewed,
            'viewedDerived' => $viewedDerived,
            'reassignCandidates' => $reassignCandidates,
        ]);
    }
}
