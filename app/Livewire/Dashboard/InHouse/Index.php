<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\InHouse;

use App\Actions\Bookings\AddBookingCharge;
use App\Actions\Bookings\ReassignRoom;
use App\Actions\Bookings\SettleCharges;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Concerns\HasExtendStayModal;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\RoomAllocator;
use App\Services\Customers\CustomerLinker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * In-house, every guest currently checked in, sorted by how soon
 * they're due to leave. This is the front-desk "who's in the house
 * right now?" surface, distinct from Reservations (arrivals /
 * upcoming) and Bookings (records list).
 *
 * KPI strip surfaces: total in-house, checkouts today, checkouts
 * tomorrow, total accrued balance across all in-house bookings.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use HasExtendStayModal;

    /**
     * Deep-link from the dashboard's Needs-attention tile - jumps
     * the page straight into a bucket panel on load. Supported
     * values match keys on the bucketIds map (in_house, today,
     * tomorrow, overdue, outstanding). Empty string = no auto-open.
     */
    #[Url(as: 'openBucket', except: '')]
    public string $openBucket = '';

    // ── Change-room inline panel ────────────────────────────────
    // Triggered from the guest-detail sheet's "Change room" action.
    // Same shared behaviour as the reservation-show + calendar
    // sheet reassign flow: cross-category, candidate pool ignores
    // past-night conflicts for CheckedIn guests.
    public bool $changeRoomOpen = false;

    public ?int $changeRoomBookingId = null;

    public ?int $changeRoomTargetRoomId = null;

    // ── Add-charge inline panel ────────────────────────────────
    // Same shape as the header AddCharge trigger on the dashboard
    // home, minus the guest search (the guest is already picked
    // from the sheet).
    public bool $addChargeOpen = false;

    public ?int $addChargeBookingId = null;

    public string $addChargeType = 'room_service';

    public string $addChargeDescription = '';

    public ?int $addChargeAmount = null;

    public bool $addChargeWaive = false;

    // ── Checkout modal ────────────────────────────────────────
    // Opened from the sheet's Check out button so the whole flow
    // stays on this page. On success we redirect to the reservation
    // summary so the operator can issue an invoice / see the paper
    // ledger.
    public bool $checkoutOpen = false;

    public ?int $checkoutBookingId = null;

    // ─── Change-room actions ────────────────────────────────────

    public function openChangeRoom(int $bookingId): void
    {
        $this->changeRoomBookingId = $bookingId;
        $this->changeRoomTargetRoomId = null;
        $this->changeRoomOpen = true;
    }

    public function closeChangeRoom(): void
    {
        $this->changeRoomOpen = false;
        $this->changeRoomTargetRoomId = null;
    }

    public function submitChangeRoom(ReassignRoom $action, CustomerLinker $linker): void
    {
        try {
            if ($this->changeRoomBookingId === null || $this->changeRoomTargetRoomId === null) {
                throw new RuntimeException('Pick a room first.');
            }
            $booking = Booking::query()->find($this->changeRoomBookingId);
            $newRoom = Room::query()->find($this->changeRoomTargetRoomId);
            if ($booking === null || $newRoom === null) {
                throw new RuntimeException('Booking or room not found.');
            }
            $op = auth('web')->user();
            if (! $op instanceof Operator) {
                throw new RuntimeException('Not signed in.');
            }
            $oldRoomNumber = $booking->room?->room_number ?? '-';
            $result = $action->handle($booking, $newRoom, $op);

            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.reassigned',
                subject: sprintf('Moved from Rm %s to Rm %s', $oldRoomNumber, $newRoom->room_number),
                body: $result->rateDelta === 0
                    ? 'Same rate, no reconciliation needed.'
                    : sprintf('Rate delta ₦%s.', number_format($result->rateDelta)),
            );

            $this->closeChangeRoom();
            $delta = (int) $result->rateDelta;
            $this->dispatch('notify',
                title: 'Room changed',
                message: sprintf(
                    'Moved to Room %s. %s',
                    $newRoom->room_number,
                    $delta === 0 ? 'No rate change.'
                        : ($delta > 0 ? '+₦'.number_format($delta).' to collect from the guest.'
                                     : '-₦'.number_format(abs($delta)).' owed back to the guest.'),
                ),
                kind: 'ok',
            );
        } catch (RangeNotAvailableException) {
            $this->dispatch('notify',
                title: 'Room just taken',
                message: 'That room was reserved by another guest, pick a different one.',
                kind: 'err',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t change room', message: $e->getMessage(), kind: 'err');
        }
    }

    // ── Add-charge actions ─────────────────────────────────────

    public function openAddCharge(int $bookingId): void
    {
        $this->addChargeBookingId = $bookingId;
        $this->addChargeType = 'room_service';
        $this->addChargeDescription = '';
        $this->addChargeAmount = null;
        $this->addChargeWaive = false;
        $this->addChargeOpen = true;
    }

    public function closeAddCharge(): void
    {
        $this->addChargeOpen = false;
    }

    public function submitAddCharge(AddBookingCharge $add, SettleCharges $settle): void
    {
        try {
            if ($this->addChargeBookingId === null) {
                throw new RuntimeException('Pick a guest first.');
            }
            if ($this->addChargeAmount === null || $this->addChargeAmount <= 0) {
                throw new RuntimeException('Enter an amount above ₦0.');
            }
            $type = BookingChargeType::tryFrom($this->addChargeType);
            if ($type === null || in_array($type, [BookingChargeType::Extension, BookingChargeType::DepositForfeit], true)) {
                throw new RuntimeException('Pick a valid charge type.');
            }
            $booking = Booking::query()->find($this->addChargeBookingId);
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            if ($booking->booking_status !== BookingStatus::CheckedIn) {
                throw new RuntimeException('Guest is not currently in-house.');
            }

            $op = auth('web')->user();
            if (! $op instanceof Operator) {
                throw new RuntimeException('Not signed in.');
            }
            $description = trim($this->addChargeDescription);
            if ($description === '') {
                $description = $type->label();
            }
            $charge = $add->handle($booking, $op, $type, $description, (int) $this->addChargeAmount);
            if ($this->addChargeWaive) {
                $settle->settleOne($charge, $op, 'waived');
            }

            $this->closeAddCharge();
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
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t add charge', message: $e->getMessage(), kind: 'err');
        }
    }

    // ─── Checkout actions ────────────────────────────────────────

    public function openCheckout(int $bookingId): void
    {
        $this->checkoutBookingId = $bookingId;
        $this->checkoutOpen = true;
    }

    public function closeCheckout(): void
    {
        $this->checkoutOpen = false;
        $this->checkoutBookingId = null;
    }

    /**
     * Fire the checkout on the picked booking + redirect to the
     * reservation summary page so the operator can issue an invoice.
     * The MarkCheckedOut action itself enforces the outstanding rule
     * (corp-guaranteed bookings are exempt; walk-ins with balance owed
     * still get refused — the modal already prompts the operator to
     * settle first in that case).
     */
    public function submitCheckout(\App\Actions\Bookings\MarkCheckedOut $action, CustomerLinker $linker)
    {
        try {
            if ($this->checkoutBookingId === null) {
                throw new RuntimeException('No booking picked.');
            }
            $booking = Booking::query()->with(['room', 'corporate'])->find($this->checkoutBookingId);
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            $op = auth('web')->user();
            if (! $op instanceof Operator) {
                throw new RuntimeException('Not signed in.');
            }

            // MarkCheckedOut's own guard applies. Corp guests skip the
            // outstanding refusal (we already exempted them there); a
            // walk-in with balance owed hits the RuntimeException and
            // gets bounced into a notify with the amount to settle.
            $action->handle($booking, $op, 'keep');

            $linker->recordBookingEvent(
                booking: $booking->refresh(),
                event: 'booking.checked_out',
                subject: $booking->guest_name.' checked out',
                body: 'Total paid ₦'.number_format((int) $booking->total_amount),
            );

            // Bust the 60-second dashboard cache so the operator sees
            // the just-checked-out corp guest's balance flip from
            // "In-house" to "Ready to invoice" on the next hit.
            \App\Services\Dashboard\DashboardSnapshot::forget();

            $ref = (string) $booking->booking_ref;
            $this->closeCheckout();
            return $this->redirect(
                route('tenant.reservations.show', $ref),
                navigate: true,
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t check out', message: $e->getMessage(), kind: 'err');
            return null;
        }
    }

    public function render(): View
    {
        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();

        $propertyId = app(\App\Services\Properties\PropertyContext::class)->current()?->getKey();

        $inHouse = Booking::query()
            // Category.base_rate powers $room->base_rate (an accessor,
            // not a column) which outstandingTotal() reads to project
            // unbilled overstay; category also holds the breakfast
            // tier the daily-rate display switches on.
            ->with(['room:id,room_number,room_category_id,property_id', 'room.category:id,name,base_rate,breakfast_included_rate', 'customer', 'corporate'])
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->orderBy('checkout_date')
            ->orderBy('guest_name')
            ->get();

        $checkoutsTodaySet = $inHouse->filter(
            fn (Booking $b) => $b->checkout_date && $b->checkout_date->isSameDay($today),
        );
        $checkoutsTomorrowSet = $inHouse->filter(
            fn (Booking $b) => $b->checkout_date && $b->checkout_date->isSameDay($tomorrow),
        );
        $overdueSet = $inHouse->filter(
            fn (Booking $b) => $b->checkout_date && $b->checkout_date->lt($today),
        );

        $checkoutsToday = $checkoutsTodaySet->count();
        $checkoutsTomorrow = $checkoutsTomorrowSet->count();
        $overdue = $overdueSet->count();

        $totalAccrued = $inHouse->sum(fn (Booking $b) => $b->outstandingTotal());

        // Property's advertised check-out time - powers the timeline
        // strip below. Defaults to 11:00 (industry norm) when the
        // operator hasn't configured a window.
        $property = app(\App\Services\Properties\PropertyContext::class)->current();
        $checkoutTimeStr = $property?->houseRules()['check_out_to'] ?? '';
        $checkoutHour = 11;
        $checkoutMinute = 0;
        if ($checkoutTimeStr !== '' && preg_match('/^(\d{1,2}):(\d{2})$/', $checkoutTimeStr, $m)) {
            $checkoutHour = (int) $m[1];
            $checkoutMinute = (int) $m[2];
        }

        // Pre-hydrated arrays keyed by KPI so clicking a card opens a
        // panel with just the matching booking ids - Alpine reads
        // these to filter the guest list client-side.
        // Outstanding bucket = in-house guests with a non-zero balance
        // (existing folio balance + unbilled overstay projection). Deep-
        // linked from the dashboard's Needs-attention "Bookings with
        // outstanding balance" row.
        $outstandingSet = $inHouse->filter(fn (Booking $b) => $b->outstandingTotal() > 0);

        $bucketIds = [
            'in_house'    => $inHouse->pluck('id')->all(),
            'today'       => $checkoutsTodaySet->pluck('id')->all(),
            'tomorrow'    => $checkoutsTomorrowSet->pluck('id')->all(),
            'overdue'     => $overdueSet->pluck('id')->all(),
            'outstanding' => $outstandingSet->pluck('id')->all(),
        ];

        // Timeline pins for TODAY only - one entry per guest checking
        // out today (or overdue). Position is a percentage across the
        // 06:00 → 22:00 window; a guest whose checkout is exactly at
        // the property's advertised time (11:00 by default) sits at
        // ~31% of the bar. Overdue guests get position 0 with an
        // "overdue" flag so the view can render them as a distinct
        // pin to the far left.
        $timelinePins = $checkoutsTodaySet->merge($overdueSet)
            ->unique('id')
            ->map(function (Booking $b) use ($today, $checkoutHour, $checkoutMinute): array {
                $isOverdue = $b->checkout_date && $b->checkout_date->lt($today);
                // Treat today's checkouts as scheduled at the property's
                // advertised time; overdue guests are pinned to "now"
                // for the operator's attention.
                $minutesOfDay = $isOverdue
                    ? (int) (Carbon::now()->hour * 60 + Carbon::now()->minute)
                    : ($checkoutHour * 60 + $checkoutMinute);
                return [
                    'id' => $b->id,
                    'ref' => (string) $b->booking_ref,
                    'guest' => explode(' ', trim((string) $b->guest_name))[0] ?? 'Guest',
                    'room' => $b->room?->room_number ? 'Rm '.$b->room->room_number : '-',
                    'minute' => $minutesOfDay,
                    'is_overdue' => $isOverdue,
                    'outstanding' => (int) $b->outstandingTotal(),
                ];
            })
            ->sortBy('minute')
            ->values()
            ->all();

        // Pre-hydrate a client-side panel payload keyed by booking id
        // so clicking a row opens the detail drawer instantly - no
        // wire trip needed, so no loader either. Also eager-loads
        // payments once for the "recent payments" block so we don't
        // fire N+1 per row.
        $inHouse->loadMissing(['payments', 'charges']);
        $now = \Illuminate\Support\Carbon::now();
        $panels = $inHouse->mapWithKeys(function (Booking $b) use ($today, $now) {
            $nightsSoFar = $b->checkin_date
                ? max(0, (int) $b->checkin_date->diffInDays($today, false))
                : 0;
            $nightsLeft = $b->checkout_date
                ? (int) $today->diffInDays($b->checkout_date, false)
                : null;
            $bookedNights = (int) ($b->nights ?? 0);
            // Committed per-night rate the guest picked at booking
            // time (breakfast tier or base). See the blade note above
            // - `room_total ÷ nights` drifted after every extend /
            // post-checkin fee and mislabelled guests as discounted.
            $paidDailyRate = $b->room?->category
                ? $b->room->category->effectiveNightlyRate((bool) $b->breakfast_included)
                : (int) ($b->room?->base_rate ?? 0);
            $recentPayments = $b->payments
                ->sortByDesc('paid_at')
                ->take(5)
                ->map(fn ($p) => [
                    'amount' => (int) $p->amount,
                    'method' => ucfirst((string) ($p->method ?? 'paystack')),
                    'paid_at' => $p->paid_at?->format('j M · H:i') ?? '-',
                    'status' => (string) $p->status->value,
                ])->values()->all();

            // Unsettled folio charges (extension, F&B added at the
            // desk, damages) so the panel explains where "Outstanding"
            // comes from instead of only showing an aggregate number.
            $openCharges = $b->charges
                ->where('settled', false)
                ->sortByDesc('created_at')
                ->take(6)
                ->map(fn ($c) => [
                    'description' => (string) $c->description,
                    'amount' => (int) $c->amount,
                    'added_at' => $c->created_at?->format('j M · H:i') ?? '-',
                ])->values()->all();

            // Same-day late: on their checkout day but past the
            // property's advertised check-out cutoff + grace. Fee is
            // projected - not charged yet - so the desk can see it
            // accruing before they run check-out. See
            // Booking::projectedLateCheckoutFee().
            $lateProjection = $b->projectedLateCheckoutFee($now);
            $isSameDayLate = $lateProjection['kind'] !== 'none';

            return [$b->id => [
                'id' => $b->id,
                'ref' => (string) $b->booking_ref,
                'guest_name' => (string) $b->guest_name,
                'guest_phone' => (string) ($b->guest_phone ?? ''),
                'guest_email' => (string) ($b->guest_email ?? ''),
                'corporate' => (string) ($b->corporate?->name ?? ''),
                // Corporate-guaranteed stays don't take cash at the
                // desk — the org settles by invoice. The Settle button
                // in the slideout is hidden for these, and checkout is
                // permitted with balance_due > 0 (see checkOut flow).
                'is_corp_preauth' => $b->isCorporatePreAuthorized(),
                'room' => $b->room?->room_number ? 'Rm '.$b->room->room_number : '-',
                'category' => (string) ($b->room?->category?->name ?? ''),
                'checkin' => $b->checkin_date?->format('D, j M Y') ?? '-',
                'checkout' => $b->checkout_date?->format('D, j M Y') ?? '-',
                'is_overdue' => (bool) ($b->checkout_date && $b->checkout_date->lt($today)),
                'is_same_day_late' => $isSameDayLate,
                'late_fee_projected' => (int) $lateProjection['amount'],
                'late_description' => (string) $lateProjection['description'],
                'nights_booked' => $bookedNights,
                'nights_so_far' => $nightsSoFar,
                'nights_left' => $nightsLeft,
                'daily_rate' => $paidDailyRate,
                'total' => (int) $b->total_amount,
                'deposit' => (int) $b->deposit_amount,
                'balance' => (int) $b->balance_due,
                'outstanding' => (int) $b->outstandingTotal(),
                'payments' => $recentPayments,
                'charges' => $openCharges,
                'url' => route('tenant.reservations.show', $b->booking_ref),
            ]];
        })->all();

        // Reassign candidate pool when the change-room panel is
        // open. Same shape as the reservation-show + calendar
        // sheet: cross-category, and CheckedIn guests only require
        // the REMAINING nights on the target room (past nights are
        // already spent in the current room).
        $changeRoomBooking = null;
        $changeRoomCandidates = collect();
        $changeRoomPreview = null;
        $chargeBooking = null;
        if ($this->changeRoomOpen && $this->changeRoomBookingId !== null) {
            $changeRoomBooking = $inHouse->firstWhere('id', $this->changeRoomBookingId);
            if ($changeRoomBooking !== null && $changeRoomBooking->room?->property_id !== null) {
                $todayStart = Carbon::today()->startOfDay();
                $ci = $todayStart->greaterThan($changeRoomBooking->checkin_date->copy()->startOfDay())
                    ? $todayStart : $changeRoomBooking->checkin_date;
                $changeRoomCandidates = app(RoomAllocator::class)->availableRoomsInProperty(
                    propertyId: (int) $changeRoomBooking->room->property_id,
                    checkin: $ci,
                    checkout: $changeRoomBooking->checkout_date,
                    excludeRoomId: $changeRoomBooking->room_id,
                );

                // Rate delta preview - same pro-rata math ReassignRoom
                // applies on submit: nights already spent stay at the
                // current rate, remaining nights swap to the new rate.
                // Delta = (newRate - oldRate) * remainingNights.
                if ($this->changeRoomTargetRoomId !== null) {
                    $target = $changeRoomCandidates->firstWhere('id', $this->changeRoomTargetRoomId);
                    if ($target instanceof Room) {
                        $oldRate = (int) ($changeRoomBooking->room->base_rate ?? 0);
                        $newRate = (int) ($target->base_rate ?? 0);
                        $nights = (int) $changeRoomBooking->nights;
                        $stayed = max(0, (int) $changeRoomBooking->checkin_date
                            ->copy()->startOfDay()
                            ->diffInDays($todayStart, absolute: false));
                        $stayed = min($nights, $stayed);
                        $remaining = max(0, $nights - $stayed);
                        $changeRoomPreview = [
                            'target_room_number' => (string) ($target->room_number ?? '-'),
                            'target_category'    => (string) ($target->category?->name ?? ''),
                            'old_rate'           => $oldRate,
                            'new_rate'           => $newRate,
                            'remaining_nights'   => $remaining,
                            'delta'              => ($newRate - $oldRate) * $remaining,
                        ];
                    }
                }
            }
        }
        if ($this->addChargeOpen && $this->addChargeBookingId !== null) {
            $chargeBooking = $inHouse->firstWhere('id', $this->addChargeBookingId);
        }

        return view('livewire.dashboard.in-house.index', [
            'today'             => $today,
            'inHouse'           => $inHouse,
            'changeRoomBooking' => $changeRoomBooking,
            'changeRoomCandidates' => $changeRoomCandidates,
            'changeRoomPreview' => $changeRoomPreview,
            'chargeBooking'     => $chargeBooking,
            'chargeTypeOptions' => [
                'room_service'  => 'Room service',
                'food_beverage' => 'Food & beverage',
                'laundry'       => 'Laundry',
                'transport'     => 'Transport',
                'damage'        => 'Damage',
                'other'         => 'Other',
            ],
            'counts'            => [
                'in_house'           => $inHouse->count(),
                'checkouts_today'    => $checkoutsToday,
                'checkouts_tomorrow' => $checkoutsTomorrow,
                'overdue'            => $overdue,
            ],
            'totalAccruedNaira' => (int) $totalAccrued,
            'panels'            => $panels,
            'bucketIds'         => $bucketIds,
            'timelinePins'      => $timelinePins,
            'checkoutTimeStr'   => sprintf('%02d:%02d', $checkoutHour, $checkoutMinute),
            'openBucketInit'    => $this->openBucket,
        ]);
    }
}
