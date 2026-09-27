<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Actions\Bookings\AddBookingCharge;
use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Bookings\MarkNoShow;
use App\Actions\Bookings\ReassignRoom;
use App\Actions\Bookings\RecordManualPayment;
use App\Actions\Bookings\RecordPartPayment;
use App\Actions\Bookings\SettleCharges;
use App\Enums\BookingChargeType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Livewire\Concerns\HasCalendarSearch;
use App\Livewire\Concerns\HasCustomerLookup;
use App\Livewire\Concerns\HasDiscountLookup;
use App\Livewire\Concerns\HasExtendStayModal;
use App\Livewire\Dashboard\Calendar\PropertyView;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomInspection;
use App\Models\Tenant;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\RoomAllocator;
use App\Services\Customers\CustomerLinker;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Operator dashboard home (PRD §6.2.1).
 *
 * Pulls every widget's data from {@see DashboardSnapshot} so the page
 * is one cache lookup per request rather than N queries per widget.
 *
 * The mini 14-day heatmap is interactive, same click/drag/booked-click
 * pattern as the full property calendar, so operators can take quick
 * action without leaving the dashboard. Action-sheet logic mirrors
 * {@see PropertyView}; both share the
 * `_partials/calendar-sheets.blade.php` template + bind-by-attribute JS.
 */
class Home extends Component
{
    use HasCalendarSearch;
    use HasCustomerLookup;
    use HasDiscountLookup;
    use HasExtendStayModal;

    // ── New-reservation sheet state ──────────────────────────────────
    public bool $sheetOpen = false;

    public ?int $sheetRoomId = null;

    public string $sheetRoomName = '';

    public string $sheetCheckin = '';

    public string $sheetCheckout = '';

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

    #[Validate('required|in:pending,succeeded')]
    // Default to 'pending' so a fresh reservation with no payment
    // touch DOES NOT auto-record a phantom cash payment for the
    // full amount. The operator must actively pick "Paid in full"
    // to record settlement — every other path (deposit / wallet /
    // pending) is safe because it either takes an explicit amount
    // or records no payment at all.
    public string $payment_settlement = 'pending';

    #[Validate('required|string')]
    public string $source = 'walk_in';

    #[Validate('nullable|string|max:120')]
    public string $source_note = '';

    #[Validate('boolean')]
    public bool $breakfast_included = false;

    // ── View-existing-booking sheet state ────────────────────────────
    public bool $bookingSheetOpen = false;

    public ?int $viewBookingId = null;

    public bool $cancelRefund = false;

    public bool $noShowForfeit = true;

    public bool $showReassign = false;

    public ?int $reassignTargetRoomId = null;

    // ── Add-charge panel state ─────────────────────────────────────
    // Header "Add charge" dropdown lets the front desk drop a
    // charge onto any in-house guest without opening the reservation
    // page. Supports the two common flows the desk hits over the
    // shift: an ad-hoc purchase (room service, food, etc.) and a
    // damage fee that can be waived on the spot. POS charges route
    // through the POS terminal instead - a "Open POS" link at the
    // foot of the panel jumps the operator there.
    public bool $addChargeOpen = false;

    public string $addChargeSearch = '';

    public ?int $addChargeBookingId = null;

    public string $addChargeType = 'room_service';

    public string $addChargeDescription = '';

    public ?int $addChargeAmount = null;

    public bool $addChargeWaive = false;

    // ── Outstanding-balances panel state ──────────────────────────
    // Clicking the "Outstanding" pulse tile opens a full sheet with
    // every booking that still owes money. Each row can be settled
    // in one click (SettleCharges routes cash / wallet against the
    // full outstanding) or the operator can inline a part payment
    // when the guest can't clear it all at the counter.
    public bool $outstandingOpen = false;

    // Per-row part-payment state: {bookingId, amount, method}.
    // Only one row's form is open at a time.
    public ?int $outstandingPartPayBookingId = null;

    public ?int $outstandingPartPayAmount = null;

    public string $outstandingPartPayMethod = 'cash';

    public string $outstandingSettleMethod = 'cash';

    // Banners (shared across both sheets)
    public function mount(): void
    {
        $this->initCalendarSearch();
    }

    /**
     * Bound to wire:poll on the dashboard root so the heatmap +
     * arrivals/departures/outstanding widgets stay current. Busts the
     * DashboardSnapshot cache so the next render() pulls fresh data -
     * polling is pointless if it just re-reads the same cached payload.
     */
    public function refresh(): void
    {
        DashboardSnapshot::forget();
    }

    // ─── New-reservation flow ────────────────────────────────────────

    #[On('open-reservation-sheet')]
    public function openSheet(int $roomId, string $checkin, string $checkout): void
    {
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

    public function saveReservation(CreateBooking $create, RecordManualPayment $recordPayment): void
    {
        $this->validate();

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

        $isPaid = $this->payment_settlement === 'succeeded';
        $booking->forceFill([
            'booking_status' => $isPaid ? BookingStatus::Confirmed : BookingStatus::Pending,
            'payment_status' => $isPaid ? PaymentStatus::Succeeded : PaymentStatus::Pending,
        ])->save();

        // Log the off-Paystack receipt on the Payment ledger so Finance
        // + Reports see the cash. No-op when the operator marked it unpaid.
        if ($isPaid) {
            $recordPayment->handle($booking->refresh(), auth('web')->user());
        }

        DashboardSnapshot::forget();

        $this->redirect(route('tenant.bookings.show', $booking->booking_ref), navigate: true);
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

    /**
     * Reset + open the add-charge panel. Called from the header
     * "Add charge" button. Clears any stale pick from a prior open.
     */
    public function openAddChargePanel(): void
    {
        $this->addChargeSearch = '';
        $this->addChargeBookingId = null;
        $this->addChargeType = 'room_service';
        $this->addChargeDescription = '';
        $this->addChargeAmount = null;
        $this->addChargeWaive = false;
        $this->addChargeOpen = true;
    }

    public function closeAddChargePanel(): void
    {
        $this->addChargeOpen = false;
    }

    /**
     * Submit the add-charge form. Guest is picked from the
     * in-house list (or by room number via the search input); the
     * charge is created via {@see AddBookingCharge}. When the
     * "Waive" toggle is on, SettleCharges immediately flips the
     * charge to settled_method='waived' so the accountant sees the
     * full audit trail (damage occurred + operator waived on the
     * spot) rather than a silent skip.
     */
    public function submitAddCharge(AddBookingCharge $add, SettleCharges $settle): void
    {
        $this->runBookingAction(function () use ($add, $settle): void {
            if ($this->addChargeBookingId === null) {
                throw new RuntimeException('Pick an in-house guest first.');
            }
            if ($this->addChargeAmount === null || $this->addChargeAmount <= 0) {
                throw new RuntimeException('Enter an amount above ₦0.');
            }
            $type = BookingChargeType::tryFrom($this->addChargeType);
            if ($type === null) {
                throw new RuntimeException('Pick a valid charge type.');
            }
            // System-only types can't be raised manually; AddBookingCharge
            // enforces this too but we surface a clean message here
            // rather than letting an InvalidArgumentException bubble.
            if (in_array($type, [BookingChargeType::Extension, BookingChargeType::DepositForfeit], true)) {
                throw new RuntimeException('That charge type is set by the system, pick a different one.');
            }

            $booking = Booking::query()->find($this->addChargeBookingId);
            if ($booking === null) {
                throw new RuntimeException('Guest not found.');
            }
            if ($booking->booking_status !== BookingStatus::CheckedIn) {
                throw new RuntimeException('Guest is not currently in-house.');
            }

            $operator = auth('web')->user();
            $description = trim($this->addChargeDescription);
            if ($description === '') {
                $description = $type->label();
            }

            $charge = $add->handle($booking, $operator, $type, $description, (int) $this->addChargeAmount);

            // "Waive" = the charge exists on the ledger for the audit
            // trail but the operator forgave it on the spot. Uses
            // settled_method='waived' so revenue reports can exclude
            // waived rows while still showing them on the folio.
            if ($this->addChargeWaive) {
                $settle->settleOne($charge, $operator, 'waived');
            }

            \App\Services\Dashboard\DashboardSnapshot::forget();

            $this->addChargeOpen = false;
            $this->dispatch('notify',
                title: 'Charge added',
                message: sprintf('₦%s %s%s added to %s.',
                    number_format((int) $this->addChargeAmount),
                    $type->label(),
                    $this->addChargeWaive ? ' (waived)' : '',
                    $booking->guest_name,
                ),
                kind: 'ok',
            );
        });
    }

    // ── Outstanding-balances panel ────────────────────────────────
    // Clicking the "Outstanding" pulse tile opens a full sheet with
    // every booking that still owes the platform money. Each row can
    // be settled in one shot or the operator can take a part payment
    // inline when the guest can't clear it all at the counter.

    public function openOutstandingPanel(): void
    {
        $this->outstandingOpen = true;
        $this->resetOutstandingPartPay();
    }

    public function closeOutstandingPanel(): void
    {
        $this->outstandingOpen = false;
        $this->resetOutstandingPartPay();
    }

    public function openOutstandingPartPay(int $bookingId): void
    {
        $this->outstandingPartPayBookingId = $bookingId;
        $this->outstandingPartPayAmount = null;
        $this->outstandingPartPayMethod = 'cash';
    }

    public function closeOutstandingPartPay(): void
    {
        $this->resetOutstandingPartPay();
    }

    /**
     * Collect the FULL outstanding on a row in one click. Uses the
     * shared RecordPartPayment (amount = outstandingTotal) so the
     * ledger behaviour is identical to the Reservations Show
     * "Settle everything" button — one Payment row for the total,
     * balance_due to 0, every unsettled charge marked paid.
     */
    public function settleOutstandingAll(int $bookingId, RecordPartPayment $record): void
    {
        $this->runBookingAction(function () use ($bookingId, $record): void {
            $booking = Booking::query()->findOrFail($bookingId);
            $owed = $booking->outstandingTotal();
            if ($owed <= 0) {
                throw new RuntimeException('Nothing outstanding to settle.');
            }
            $operator = auth('web')->user();
            if (! $operator instanceof Operator) {
                throw new RuntimeException('You must be signed in.');
            }
            $record->handle($booking, $operator, $owed, $this->outstandingSettleMethod);

            \App\Services\Dashboard\DashboardSnapshot::forget();
            $this->dispatch('notify',
                title: 'Settled',
                message: sprintf('₦%s taken from %s via %s.',
                    number_format($owed),
                    $booking->guest_name,
                    $this->outstandingSettleMethod,
                ),
                kind: 'ok',
            );
        });
    }

    /**
     * Collect a partial amount on a row. Delegates to
     * {@see RecordPartPayment} which applies to balance_due first
     * then chips unsettled charges oldest-first.
     */
    public function submitOutstandingPartPay(RecordPartPayment $record): void
    {
        $this->runBookingAction(function () use ($record): void {
            if ($this->outstandingPartPayBookingId === null) {
                throw new RuntimeException('Pick a row first.');
            }
            $booking = Booking::query()->findOrFail($this->outstandingPartPayBookingId);
            $operator = auth('web')->user();
            if (! $operator instanceof Operator) {
                throw new RuntimeException('You must be signed in.');
            }
            $owed = $booking->outstandingTotal();
            $amount = (int) ($this->outstandingPartPayAmount ?? 0);

            $record->handle($booking, $operator, $amount, $this->outstandingPartPayMethod);

            \App\Services\Dashboard\DashboardSnapshot::forget();
            $remaining = max(0, $owed - $amount);
            $this->resetOutstandingPartPay();
            $this->dispatch('notify',
                title: 'Part payment recorded',
                message: sprintf(
                    '₦%s taken from %s. %s',
                    number_format($amount),
                    $booking->guest_name,
                    $remaining > 0
                        ? '₦'.number_format($remaining).' still outstanding.'
                        : 'Booking is now settled.',
                ),
                kind: 'ok',
            );
        });
    }

    private function resetOutstandingPartPay(): void
    {
        $this->outstandingPartPayBookingId = null;
        $this->outstandingPartPayAmount = null;
        $this->outstandingPartPayMethod = 'cash';
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
                throw new RuntimeException('That room was just taken, pick another.');
            }

            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.room_reassigned',
                subject: 'Room changed from '.$oldRoomNumber.' to '.($newRoom->room_number ?? '-'),
                body: null,
            );

            DashboardSnapshot::forget();
            $this->showReassign = false;
            $this->reassignTargetRoomId = null;
            $this->dispatch('notify', title: 'Done', message: 'Reassigned to room '.($newRoom->room_number ?? '-').'.', kind: 'ok');
        });
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
            DashboardSnapshot::forget();
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

    /**
     * Header-level "quick check-in" panel: takes a bookingId directly
     * (as opposed to `checkIn()` which operates on the currently
     * viewed booking sheet). Wired to the accent-coloured "Check in"
     * button in the dashboard header + its dropdown list of today's
     * arrivals. Confirms via wire:confirm on the row, so a stray
     * click never checks in the wrong guest.
     */
    public function quickCheckIn(int $bookingId, MarkCheckedIn $action, CustomerLinker $linker): void
    {
        $this->runBookingAction(function () use ($bookingId, $action, $linker): void {
            $booking = Booking::query()->find($bookingId);
            if ($booking === null) {
                throw new RuntimeException('Reservation not found.');
            }
            $operator = auth('web')->user();
            $action->handle($booking, $operator);
            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.checked_in',
                subject: $booking->guest_name.' checked in',
                body: 'Room '.$booking->room?->name,
            );
            DashboardSnapshot::forget();
            if ($action->pendingInspection !== null) {
                $this->dispatch('notify',
                    title: $booking->guest_name.' checked in — room needs cleaning attention',
                    message: sprintf(
                        'Housekeeping is still on this room (waiting on %s). Follow up before the guest complains.',
                        $action->pendingInspection->status->waitingOn(),
                    ),
                    kind: 'warn',
                );
            } else {
                $this->dispatch('notify',
                    title: 'Checked in',
                    message: $booking->guest_name.' is now in-house.',
                    kind: 'ok',
                );
            }
        });
    }

    /**
     * Mirror of {@see self::quickCheckIn()} for the departure panel.
     * Guards on outstandingTotal == 0 so a stray click can't check
     * out a guest with unsettled charges (the blade also disables the
     * per-row button in that case; this is the belt-and-suspenders
     * server-side check so the guard holds even if the DOM is racy).
     */
    public function quickCheckOut(int $bookingId, MarkCheckedOut $action, CustomerLinker $linker): void
    {
        $this->runBookingAction(function () use ($bookingId, $action, $linker): void {
            $booking = Booking::query()->find($bookingId);
            if ($booking === null) {
                throw new RuntimeException('Reservation not found.');
            }
            if ($booking->outstandingTotal() > 0) {
                throw new RuntimeException(
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
            DashboardSnapshot::forget();
            $this->dispatch('notify',
                title: 'Checked out',
                message: $booking->guest_name.' has been checked out.',
                kind: 'ok',
            );
        });
    }

    public function checkOut(MarkCheckedOut $action, CustomerLinker $linker): void
    {
        $this->runBookingAction(function () use ($action, $linker): void {
            $booking = $this->viewedBooking();
            if ($booking->outstandingTotal() > 0) {
                throw new RuntimeException(
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
            DashboardSnapshot::forget();
            $this->dispatch('notify', title: 'Done', message: 'Checked out, heatmap refreshed.', kind: 'ok');
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
            DashboardSnapshot::forget();
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
            DashboardSnapshot::forget();
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
        $this->payment_settlement = 'pending';
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
            $preview = app(PricingCalculator::class)->calculate($room, $checkin, $checkout);
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

    public function render(DashboardSnapshot $snapshot, RoomAllocator $allocator): View
    {
        // Housekeeper / porter / supervisor never touch bookings, the
        // manager-heavy heatmap + arrivals view is noise for them. Send
        // them to a tuned dashboard that shows their queue, what they've
        // done today, and what's blocked. Everyone else (manager,
        // reservation officer, frontdesk, etc.) keeps the standard view.
        $currentOperator = auth('web')->user();
        $role = $currentOperator instanceof \App\Models\Operator ? $currentOperator->role : null;
        $housekeepingRoles = [
            \App\Enums\OperatorRole::HouseCleaner,
            \App\Enums\OperatorRole::Porter,
            \App\Enums\OperatorRole::Supervisor,
        ];
        if ($role !== null && in_array($role, $housekeepingRoles, true)) {
            return $this->renderHousekeepingHome($currentOperator, $role);
        }

        $data = $snapshot->build();

        // Keep auto-apply / manual code in sync with whatever room +
        // dates are currently in the sheet. No-op when sheet is closed.
        if ($this->sheetOpen) {
            $this->refreshDiscount();
        }

        // Resolve the viewed booking + its derived state, only when the
        // booking-info sheet is open. Same pattern as PropertyView so
        // the shared sheets partial sees the same shape.
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
                    'confirmed' => 'lp-tag--confirmed',
                    'on_hold' => 'lp-tag--on-hold',
                    'pending' => 'lp-tag--pending',
                    'cancelled' => 'lp-tag--cancelled',
                    'checked_in' => 'lp-tag--checked-in',
                    'checked_out' => 'lp-tag--checked-out',
                    'no_show' => 'lp-tag--cancelled',
                    default => '',
                };
                $viewedDerived['unsettledTotal'] = (int) $viewed->charges->where('settled', false)->sum('amount');
                $viewedDerived['outstandingTotal'] = $viewed->outstandingTotal();
                $today = Carbon::today();
                $checkinDate = $viewed->checkin_date->copy()->startOfDay();
                $viewedDerived['canCheckIn'] = in_array($viewed->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true)
                    && $checkinDate->toDateString() === $today->toDateString();
                $viewedDerived['canCheckOut'] = $viewed->booking_status === BookingStatus::CheckedIn;
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

                // Overstay preview uses the shared Booking method so this
                // stays in sync with the reservations show page + the
                // dashboard/finance receivables rollups.
                $overstayNights = $viewed->projectedOverstayNights($today);
                if ($overstayNights > 0) {
                    $viewedDerived['overstayNights'] = $overstayNights;
                    $viewedDerived['overstayAmount'] = $viewed->projectedOverstayAmount($today);
                }
                $viewedDerived['overstayAlreadyCharged'] = $viewed->charges
                    ->where('type', BookingChargeType::Overstay)
                    ->isNotEmpty();
            }
        }

        $reassignCandidates = collect();
        if ($viewed !== null && ($viewedDerived['canReassign'] ?? false) && $viewed->room?->category !== null) {
            $reassignCandidates = $allocator->availableRooms(
                category: $viewed->room->category,
                checkin: $viewed->checkin_date,
                checkout: $viewed->checkout_date,
                excludeRoomId: $viewed->room_id,
            );
        }

        // Quick-search: mask the heatmap's room list based on the
        // operator's roomSearch + availability filter. The snapshot
        // itself stays cached, we filter its output only.
        $heatmapRooms = $data['heatmap']['rooms'] ?? collect();
        $totalRoomCount = $heatmapRooms->count();
        $allowedIds = $this->calendarSearchAllowedRoomIds($heatmapRooms);
        $visibleHeatmapRooms = $heatmapRooms
            ->filter(fn ($r) => isset($allowedIds[$r->id]))
            ->values();

        // Rebuild grouped so category headers with 0 matches disappear.
        $filteredGrouped = collect();
        if (isset($data['heatmap']['grouped'])) {
            $filteredGrouped = $data['heatmap']['grouped']
                ->map(function ($group) use ($allowedIds) {
                    $rooms = collect($group->rooms ?? [])
                        ->filter(fn ($r) => isset($allowedIds[$r->id]))
                        ->values();
                    // Return a clone with the filtered rooms so we don't
                    // mutate the cached snapshot object.
                    return (object) [
                        'category' => $group->category ?? null,
                        'name'  => $group->name,
                        'type'  => $group->type ?? null,
                        'rooms' => $rooms,
                    ];
                })
                ->filter(fn ($group) => $group->rooms->isNotEmpty())
                ->values();
        }

        $data['heatmap']['rooms'] = $visibleHeatmapRooms;
        $data['heatmap']['grouped'] = $filteredGrouped;

        // Sheet's picked room for the breakfast-tier radio pair. Reload
        // via query rather than the snapshot's rooms collection because
        // heatmap only pre-fetches a slice of columns.
        $sheetRoom = $this->sheetRoomId !== null
            ? Room::query()->with('category')->find($this->sheetRoomId)
            : null;
        if ($sheetRoom !== null && ! $sheetRoom->category?->hasBreakfastOption()) {
            $this->breakfast_included = false;
        }

        // "Rooms needing attention", every open room inspection with
        // its progress through the three-signer chain. Powers the
        // widget that replaced the mini heatmap on the dashboard.
        //   0/3 → Pending          (not yet cleaned)
        //   1/3 → CleaningDone     (housekeeper submitted)
        //   2/3 → PorterVerified   (porter cleared, waiting on supervisor)
        //   ⚠   → Failed           (rejected; needs a fresh pass)
        $attentionRows = RoomInspection::query()
            ->with(['room.category'])
            ->whereNotIn('status', [RoomInspectionStatus::Passed->value])
            ->orderByRaw("CASE
                WHEN status = 'failed' THEN 0
                WHEN status = 'porter_verified' THEN 1
                WHEN status = 'cleaning_done' THEN 2
                WHEN status = 'pending' THEN 3
                ELSE 4 END")
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(function (RoomInspection $ins): array {
                $signed = match ($ins->status) {
                    RoomInspectionStatus::Pending => 0,
                    RoomInspectionStatus::CleaningDone => 1,
                    RoomInspectionStatus::PorterVerified => 2,
                    RoomInspectionStatus::Passed => 3,
                    RoomInspectionStatus::Failed => 0,
                };

                return [
                    'inspection' => $ins,
                    'signed' => $signed,
                    'total' => 3,
                    'waiting_on' => $ins->status->waitingOn(),
                    'is_failed' => $ins->status === RoomInspectionStatus::Failed,
                ];
            });

        // Full outstanding-balances list for the panel. Always
        // computed so Alpine can flip the modal open instantly
        // without waiting for a fresh render round-trip — the query
        // is a single indexed scan over open books, cheap enough
        // that eagerly loading it on every render is worth the
        // snappy UX.
        $today = Carbon::today();
        $outstandingPanel = Booking::query()
            ->with(['room', 'charges'])
            ->whereIn('booking_status', [BookingStatus::OnHold, BookingStatus::CheckedIn])
            ->where(function ($q) use ($today): void {
                $q->where('balance_due', '>', 0)
                    ->orWhereExists(function ($sub): void {
                        $sub->from('booking_charges')
                            ->whereColumn('booking_charges.booking_id', 'bookings.id')
                            ->where('booking_charges.settled', false);
                    })
                    ->orWhere(function ($qq) use ($today): void {
                        $qq->where('booking_status', BookingStatus::CheckedIn->value)
                            ->whereDate('checkout_date', '<', $today->toDateString());
                    });
            })
            ->orderByRaw('COALESCE(balance_due_at, checkin_date) asc')
            ->get()
            ->filter(fn (Booking $b) => $b->outstandingTotal() > 0)
            ->values();

        return view('livewire.dashboard.home', array_merge($data, [
            'viewed' => $viewed,
            'viewedDerived' => $viewedDerived,
            'reassignCandidates' => $reassignCandidates,
            'visibleRoomCount' => $visibleHeatmapRooms->count(),
            'totalRoomCount' => $totalRoomCount,
            'sheetRoom' => $sheetRoom,
            'attentionRows' => $attentionRows,
            'outstandingPanel' => $outstandingPanel,
        ]))->layout('layouts.tenant-auth');
    }

    /**
     * Role-tuned dashboard for HouseCleaner / Porter / Supervisor.
     * Each role sees:
     *   – A KPI strip: the queue waiting on THEM, what they touched today,
     *     and whatever's blocked / outstanding across the pipeline.
     *   – A "waiting on you" list: the actual inspections they can act on
     *     right now, linked to the inspection page.
     *   – A "recent activity" list: what they signed off today.
     */
    private function renderHousekeepingHome(\App\Models\Operator $op, \App\Enums\OperatorRole $role): View
    {
        $today = Carbon::today();
        $todayStart = $today->copy()->startOfDay();
        $todayEnd = $today->copy()->endOfDay();

        // Waiting-on-me depends on which stage of the sign-off chain
        // this role owns. Supervisor also acts as a fallback for the
        // housekeeper/porter stages, but the dashboard emphasises the
        // stage the role uniquely owns, anything else clutters the queue.
        $queueStatus = match ($role) {
            \App\Enums\OperatorRole::HouseCleaner => \App\Enums\RoomInspectionStatus::Pending,
            \App\Enums\OperatorRole::Porter => \App\Enums\RoomInspectionStatus::CleaningDone,
            \App\Enums\OperatorRole::Supervisor => \App\Enums\RoomInspectionStatus::PorterVerified,
        };
        $roleLabel = match ($role) {
            \App\Enums\OperatorRole::HouseCleaner => 'Housekeeping',
            \App\Enums\OperatorRole::Porter => 'Porter',
            \App\Enums\OperatorRole::Supervisor => 'Supervisor',
        };
        $signerColumn = match ($role) {
            \App\Enums\OperatorRole::HouseCleaner => 'housekeeper_by_operator_id',
            \App\Enums\OperatorRole::Porter => 'porter_by_operator_id',
            \App\Enums\OperatorRole::Supervisor => 'supervisor_by_operator_id',
        };
        $signerAtColumn = match ($role) {
            \App\Enums\OperatorRole::HouseCleaner => 'housekeeper_at',
            \App\Enums\OperatorRole::Porter => 'porter_at',
            \App\Enums\OperatorRole::Supervisor => 'supervisor_at',
        };

        // Waiting-on-me queue.
        $queue = RoomInspection::query()
            ->with(['room.category', 'triggeredByBooking:id,booking_ref,guest_name'])
            ->where('status', $queueStatus->value)
            ->orderBy('created_at')
            ->limit(30)
            ->get();

        // Done-today = every inspection I signed as this role today.
        $doneToday = RoomInspection::query()
            ->with(['room.category'])
            ->where($signerColumn, $op->id)
            ->whereBetween($signerAtColumn, [$todayStart, $todayEnd])
            ->orderByDesc($signerAtColumn)
            ->limit(20)
            ->get();

        // Outstanding = inspections in Failed status across the whole
        // property. Anyone in the chain can see this, Failed means
        // the room can't be checked into until a fresh inspection
        // opens. Managers usually re-open, but housekeepers/porters/
        // supervisors need to know so they can flag the manager.
        $failed = RoomInspection::query()
            ->with(['room.category'])
            ->where('status', \App\Enums\RoomInspectionStatus::Failed->value)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        // KPI: queue + done-today + failed-count for the strip cards.
        // These are cheap counts, not the loaded collections above, so
        // the numbers stay accurate when the list itself is capped.
        $kpis = [
            'queue_count' => RoomInspection::query()->where('status', $queueStatus->value)->count(),
            'done_today' => RoomInspection::query()
                ->where($signerColumn, $op->id)
                ->whereBetween($signerAtColumn, [$todayStart, $todayEnd])
                ->count(),
            'failed_count' => RoomInspection::query()
                ->where('status', \App\Enums\RoomInspectionStatus::Failed->value)
                ->count(),
            // Pipeline width, how many inspections are open somewhere
            // in the chain. Gives supervisors + porters an at-a-glance
            // sense of the day's load without spelling out each stage.
            'in_flight_count' => RoomInspection::query()
                ->whereIn('status', [
                    \App\Enums\RoomInspectionStatus::Pending->value,
                    \App\Enums\RoomInspectionStatus::CleaningDone->value,
                    \App\Enums\RoomInspectionStatus::PorterVerified->value,
                ])
                ->count(),
        ];

        return view('livewire.dashboard.housekeeping-home', [
            'op' => $op,
            'role' => $role,
            'roleLabel' => $roleLabel,
            'queueStatus' => $queueStatus,
            'queue' => $queue,
            'doneToday' => $doneToday,
            'failed' => $failed,
            'kpis' => $kpis,
        ])->layout('layouts.tenant-auth');
    }
}
