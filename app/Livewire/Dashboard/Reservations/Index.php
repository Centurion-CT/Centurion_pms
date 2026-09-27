<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Reservations;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkNoShow;
use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Livewire\Concerns\HasQuickReserveForm;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Services\Properties\PropertyContext;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Dashboard\CalendarHeatmap;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Reservations, the front-desk operations console.
 *
 * Focuses on the upcoming rhythm: arrivals today, arrivals tomorrow,
 * and the longest-duration bookings on the 90-day horizon. Per-row
 * actions: cancel, mark no-show. Year heatmap for at-a-glance
 * occupancy; single-cell click opens a day-detail modal, drag-select
 * opens the reserve action sheet with the range prefilled. In-house
 * guests live on their own page (/dashboard/in-house).
 *
 * Reserve-form state + submit lives in {@see HasQuickReserveForm} so
 * the Bookings page can share the exact same action sheet.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use HasQuickReserveForm;

    /** Close-for-maintenance form state (visibility is Alpine-side). */
    public ?int $maintenanceRoomId = null;

    public string $maintenanceFrom = '';

    public string $maintenanceTo = '';

    public string $maintenanceReason = 'Maintenance';

    /**
     * Day-detail state. Visibility is Alpine-side; this component only
     * owns the `dayDetailDate` so it knows which day's summary data to
     * compute + the summary/reserve mode toggle.
     */
    public string $dayDetailDate = '';

    /** 'summary' or 'reserve', flip inside the same modal. */
    public string $dayDetailMode = 'summary';

    /** Global search input in the page header (booking ref / phone / email / name). */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Reservations page redirects back to its own booking show variant. */
    protected function quickReserveRedirectRoute(): string
    {
        return 'tenant.reservations.show';
    }

    public function resetMaintenanceForm(): void
    {
        $this->maintenanceRoomId = null;
        $this->maintenanceFrom = Carbon::today()->toDateString();
        $this->maintenanceTo = Carbon::today()->addDay()->toDateString();
        $this->maintenanceReason = 'Maintenance';
        $this->resetValidation();
    }

    /**
     * Fired from the year-calendar drag commit. `to` is INCLUSIVE
     * (last selected day); we bump it by 1 to become the exclusive
     * checkout date the booking flow expects.
     */
    public function openReserveFromCalendar(string $from, string $to): void
    {
        $checkin = Carbon::parse($from);
        $checkout = Carbon::parse($to)->addDay();
        if ($checkout->lessThanOrEqualTo($checkin)) {
            $checkout = $checkin->copy()->addDay();
        }
        $this->openReserveSheet($checkin->toDateString(), $checkout->toDateString());
    }

    /**
     * Called by the calendar cell click in the background. Sets the
     * date so render() computes the per-room summary on the next
     * cycle. Visibility is Alpine-side.
     */
    public function loadDayDetail(string $date): void
    {
        $this->dayDetailDate = Carbon::parse($date)->toDateString();
        $this->dayDetailMode = 'summary';
    }

    public function closeDayDetail(): void
    {
        $this->dayDetailMode = 'summary';
    }

    public function switchToReserveFromDayDetail(): void
    {
        $day = Carbon::parse($this->dayDetailDate);
        // Guard: the UI hides the button for past dates, but a stale
        // page or a direct wire call could still land here. Silently
        // no-op instead of seeding a nonsensical past-dated stay.
        if ($day->copy()->startOfDay()->lt(Carbon::today())) {
            return;
        }
        // Seed reserve fields without triggering the standalone
        // sheet, day-detail hosts the form inline.
        $this->resetReserveState($day->toDateString(), $day->copy()->addDay()->toDateString());
        $this->dayDetailMode = 'reserve';
    }

    public function backToDaySummary(): void
    {
        $this->dayDetailMode = 'summary';
        $this->reserveSheetOpen = false;
    }

    public function submitMaintenance(AvailabilityService $availability): void
    {
        $this->validate([
            'maintenanceRoomId' => ['required', 'integer', 'exists:rooms,id'],
            'maintenanceFrom'   => ['required', 'date'],
            'maintenanceTo'     => ['required', 'date', 'after:maintenanceFrom'],
            'maintenanceReason' => ['nullable', 'string', 'max:240'],
        ], [
            'maintenanceTo.after' => 'End date must be after the start date.',
        ]);

        $room = Room::query()->findOrFail($this->maintenanceRoomId);
        $from = Carbon::parse($this->maintenanceFrom)->startOfDay();
        $to = Carbon::parse($this->maintenanceTo)->startOfDay();

        try {
            $availability->blockRange(
                room: $room,
                checkin: $from,
                checkout: $to,
                reason: $this->maintenanceReason !== '' ? $this->maintenanceReason : 'Maintenance',
            );
        } catch (RangeNotAvailableException $e) {
            $this->addError('maintenanceRoomId', 'That room is already booked or blocked for at least one night in the range.');

            return;
        } catch (Throwable $e) {
            $this->addError('maintenanceRoomId', $e->getMessage());

            return;
        }

        session()->flash('status',
            "Closed Rm {$room->room_number} for maintenance from ".
            $from->format('j M').' to '.$to->copy()->subDay()->format('j M').'.');

        $this->resetMaintenanceForm();
        $this->dispatch('reservations-modal-close', name: 'maintenance');
    }

    public function markNoShow(string $bookingRef, MarkNoShow $action): void
    {
        $booking = Booking::query()->where('booking_ref', $bookingRef)->firstOrFail();
        $operator = $this->currentOperator();
        if ($operator === null) {
            return;
        }

        try {
            $action->handle($booking, $operator);
        } catch (Throwable $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        session()->flash('status', "Marked {$booking->booking_ref} as no-show.");
    }

    public function cancelReservation(string $bookingRef, CancelBooking $action): void
    {
        $booking = Booking::query()->where('booking_ref', $bookingRef)->firstOrFail();
        $operator = $this->currentOperator();

        try {
            $action->handle($booking, $operator);
        } catch (Throwable $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        session()->flash('status', "Cancelled reservation {$booking->booking_ref}.");
    }

    /**
     * Check a guest in from the arrivals-side panel, so the front desk
     * doesn't have to open the full reservation just to complete the
     * step. Eligibility mirrors {@see Show::render()}'s `canCheckIn`
     * gate (Confirmed/OnHold, arriving today, room assigned) and is
     * pre-computed onto the panel payload as `can_check_in` so the
     * button only surfaces when the action can succeed.
     *
     * Errors from MarkCheckedIn (Failed inspection, room still
     * physically occupied by an overstayed guest) surface through the
     * global `notify` modal so the operator sees WHY it didn't go
     * through rather than a silent no-op.
     */
    public function checkInFromPanel(string $bookingRef, MarkCheckedIn $action): void
    {
        $booking = Booking::query()->where('booking_ref', $bookingRef)->firstOrFail();
        $operator = $this->currentOperator();
        if ($operator === null) {
            return;
        }

        try {
            $action->handle($booking, $operator);
        } catch (BookingValidationException|Throwable $e) {
            $this->dispatch('notify',
                title: 'Could not check in',
                message: $e->getMessage(),
                kind: 'error',
            );

            return;
        }

        if ($action->pendingInspection !== null) {
            $this->dispatch('notify',
                title: 'Checked in, room needs cleaning attention',
                message: sprintf(
                    'Housekeeping is still on this room (waiting on %s). Follow up before the guest complains.',
                    $action->pendingInspection->status->waitingOn(),
                ),
                kind: 'warn',
            );
        } else {
            $this->dispatch('notify',
                title: 'Checked in',
                message: "{$booking->guest_name} is now in-house in Rm ".($booking->room?->room_number ?? '-').'.',
                kind: 'ok',
            );
        }
    }

    public function render(CalendarHeatmap $heatmap): View
    {
        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();
        $weekEnd = $today->copy()->addDays(7);
        $horizon = $today->copy()->addDays(90);
        $yearEnd = $today->copy()->addDays(365);

        // Scope every booking + room + availability query to the branch
        // the operator is currently working in - single-branch tenants
        // get an all-matching filter (their current property IS the
        // only property). Uses the shared helper so the buildDayDetail
        // + search paths further down apply the same scope.
        $propertyId = $this->currentPropertyId();

        $base = Booking::query()
            ->with([
                'room:id,room_number,room_category_id', 'room.category:id,name',
                // Category-only bookings have room_id NULL; eager-
                // load the roomCategory relation so the arrivals
                // table renders "Category · Unassigned" instead of
                // an em-dash when they get folded into the list.
                'roomCategory:id,name,property_id,base_rate',
                'customer', 'corporate',
            ])
            // Property scope needs to accept BOTH the room path (for
            // room-pinned bookings) AND the category path (for
            // category-only bookings). Otherwise the whereHas('room')
            // filter drops room_id=NULL bookings.
            ->when($propertyId, fn ($q, $pid) => $q->where(function ($w) use ($pid): void {
                $w->whereHas('room', fn ($r) => $r->where('property_id', $pid))
                    ->orWhereHas('roomCategory', fn ($c) => $c->where('property_id', $pid));
            }));

        // Include Pending here so category-only reservations that
        // haven't been confirmed + paid yet still surface on the
        // arrivals list - front desk needs to know they're coming.
        // Room-pinned Pending bookings existed before this change
        // too but were never surfaced; folding them in is safe
        // because the operator's action from the row (open detail)
        // is the same for either shape.
        $arrivalStatuses = [
            BookingStatus::Pending->value,
            BookingStatus::Confirmed->value,
            BookingStatus::OnHold->value,
        ];

        $arrivalsToday = (clone $base)
            ->whereDate('checkin_date', $today->toDateString())
            ->whereIn('booking_status', $arrivalStatuses)
            ->orderBy('checkin_date')
            ->orderBy('guest_name')
            ->get();

        $arrivalsTomorrow = (clone $base)
            ->whereDate('checkin_date', $tomorrow->toDateString())
            ->whereIn('booking_status', $arrivalStatuses)
            ->orderBy('checkin_date')
            ->orderBy('guest_name')
            ->get();

        // Longest upcoming, top 10 by nights across the next 90 days.
        // Sort in PHP to sidestep driver-specific date-diff SQL.
        $longest = (clone $base)
            ->whereBetween('checkin_date', [$today->toDateString(), $horizon->toDateString()])
            ->whereIn('booking_status', $arrivalStatuses)
            ->get()
            ->sortByDesc(fn (Booking $b) => $b->checkin_date && $b->checkout_date
                ? $b->checkin_date->diffInDays($b->checkout_date)
                : 0)
            ->take(10)
            ->values();

        $arrivalsThisWeek = (clone $base)
            ->whereBetween('checkin_date', [$today->toDateString(), $weekEnd->toDateString()])
            ->whereIn('booking_status', $arrivalStatuses)
            ->count();

        $inHouseCount = Booking::query()
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->count();

        $activeRooms = Room::query()
            ->where('status', RoomStatus::Active->value)
            ->when($propertyId, fn ($q, $pid) => $q->where('property_id', $pid))
            ->count();
        $bookedToday = $activeRooms > 0
            ? AvailabilityBlock::query()
                ->where('type', AvailabilityBlockType::Booked->value)
                ->whereDate('date', $today->toDateString())
                ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
                ->count()
            : 0;
        $occupancyToday = $activeRooms > 0
            ? (int) round(min($bookedToday / $activeRooms, 1) * 100)
            : null;

        // Render from the first of the current month so past days
        // in this month stay visible for exploration. Days before
        // `today` render as muted, non-interactive cells (see the
        // blade's --past variant + the pickable guard).
        $calendarFrom = $today->copy()->startOfMonth();
        $heat = $heatmap->build($calendarFrom, $yearEnd);
        $daysByMonth = [];
        foreach ($heat['days'] as $iso => $day) {
            $key = $day['date']->format('Y-m');
            $daysByMonth[$key][$iso] = $day;
        }

        $rooms = Room::query()
            ->where('status', RoomStatus::Active->value)
            ->when($this->currentPropertyId(), fn ($q, $pid) => $q->where('property_id', $pid))
            ->with('category:id,name,base_rate,breakfast_included_rate')
            ->orderBy('room_number')
            ->get();

        // Reserve-form dropdown: filter down to rooms available across
        // the picked date range (trait helper).
        $availableRooms = $this->availableRoomsForReserve($rooms);

        $inHouseSheetData = $this->buildInHouseSheetData($today, $tomorrow);

        $dayDetailData = $this->dayDetailDate !== ''
            ? $this->buildDayDetail(Carbon::parse($this->dayDetailDate))
            : null;

        $searchResults = $this->searchResults();

        // Category-only (unassigned) bookings live in this same list
        // but slip past the earlier arrivals queries (which filter
        // by whereHas('room', ...) - unassigned bookings have no
        // room). Surfaced as their own card so operators see the
        // work-item count + can jump straight to each reservation
        // to pick a room + take payment.
        $unassignedBookings = Booking::query()
            ->with([
                'roomCategory:id,name,base_rate',
                // Eager-load payments + auto-discounts so the table can
                // render "Paid" + "Discount" columns without N+1.
                'payments:id,booking_id,amount,status',
                'appliedDiscounts:id,booking_id,amount_off',
            ])
            ->whereNull('room_id')
            ->whereNotNull('room_category_id')
            ->whereIn('booking_status', [
                BookingStatus::Pending->value,
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
            ])
            ->when($propertyId, fn ($q, $pid) => $q->whereHas(
                'roomCategory', fn ($r) => $r->where('property_id', $pid),
            ))
            ->orderBy('checkin_date')
            ->orderBy('guest_name')
            ->limit(50)
            ->get();

        // Pre-hydrate a client-side panel payload for both arrivals
        // lists so clicking a row opens a rich detail panel without a
        // server round-trip. Bounded by the arrivals count (typically
        // a handful) so the N+1 cost from ->payments is negligible.
        $arrivalsPanelData = $this->buildArrivalsPanelData($arrivalsToday, $arrivalsTomorrow);

        return view('livewire.dashboard.reservations.index', [
            'unassignedBookings' => $unassignedBookings,
            'today'            => $today,
            'tomorrow'         => $tomorrow,
            'arrivalsToday'    => $arrivalsToday,
            'arrivalsTomorrow' => $arrivalsTomorrow,
            'longest'          => $longest,
            'counts'           => [
                'arrivals_today'    => $arrivalsToday->count(),
                'arrivals_tomorrow' => $arrivalsTomorrow->count(),
                'arrivals_week'     => $arrivalsThisWeek,
                'in_house'          => $inHouseCount,
                'occupancy_today'   => $occupancyToday,
            ],
            'daysByMonth'      => $daysByMonth,
            'totalActiveRooms' => $heat['total_rooms'],
            'rooms'            => $rooms,
            'availableRooms'   => $availableRooms,
            'inHouseSheetData' => $inHouseSheetData,
            'dayDetailData'    => $dayDetailData,
            'searchResults'    => $searchResults,
            'arrivalsPanelData' => $arrivalsPanelData,
        ]);
    }

    /**
     * Build the JS-consumable payload for the arrival detail panel.
     * Combines today + tomorrow into one keyed map so a single Alpine
     * component can serve both tables.
     *
     * @param  \Illuminate\Support\Collection<int, Booking>  $today
     * @param  \Illuminate\Support\Collection<int, Booking>  $tomorrow
     * @return array<int, array<string, mixed>>
     */
    private function buildArrivalsPanelData($today, $tomorrow): array
    {
        $merged = $today->merge($tomorrow);
        if ($merged->isEmpty()) {
            return [];
        }
        $merged->loadMissing(['payments']);
        $today = Carbon::today()->toDateString();
        return $merged->mapWithKeys(function (Booking $b) use ($today): array {
            $nights = $b->checkin_date && $b->checkout_date
                ? max(0, (int) $b->checkin_date->diffInDays($b->checkout_date))
                : 0;
            // Mirror Show@render's `canCheckIn` gate. Arriving-tomorrow
            // panels always fail this (checkin_date != today), so the
            // Check-in button stays hidden there without extra logic.
            $canCheckIn = in_array($b->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true)
                && $b->room_id !== null
                && $b->checkin_date?->toDateString() === $today;
            $payments = $b->payments
                ->sortByDesc('paid_at')
                ->take(3)
                ->map(fn ($p) => [
                    'amount' => (int) $p->amount,
                    'method' => ucfirst((string) ($p->method ?? 'paystack')),
                    'paid_at' => $p->paid_at?->format('j M · H:i') ?? '-',
                ])->values()->all();
            return [$b->id => [
                'id' => $b->id,
                'ref' => (string) $b->booking_ref,
                'guest_name' => (string) $b->guest_name,
                'guest_phone' => (string) ($b->guest_phone ?? ''),
                'guest_email' => (string) ($b->guest_email ?? ''),
                'corporate' => (string) ($b->corporate?->name ?? ''),
                'room' => $b->room?->room_number ?? '-',
                'category' => (string) ($b->room?->category?->name ?? ''),
                'checkin' => $b->checkin_date?->format('D, j M Y') ?? '-',
                'checkout' => $b->checkout_date?->format('D, j M Y') ?? '-',
                'nights' => $nights,
                'status' => (string) ($b->booking_status?->value ?? ''),
                'status_label' => (string) ($b->booking_status?->label() ?? ''),
                'total' => (int) ($b->total_amount ?? 0),
                'deposit' => (int) ($b->deposit_amount ?? 0),
                'balance' => (int) ($b->balance_due ?? 0),
                'outstanding' => (int) $b->outstandingTotal(),
                'payments' => $payments,
                'url' => route('tenant.reservations.show', $b->booking_ref),
                'special_requests' => (string) ($b->special_requests ?? ''),
                'can_check_in' => $canCheckIn,
            ]];
        })->all();
    }

    /**
     * @return array{
     *     date: Carbon, rooms: \Illuminate\Support\Collection<int, array>,
     *     kpi: array{arrivals:int, departures:int, in_house:int, blocked:int, free:int},
     * }
     */
    private function buildDayDetail(Carbon $date): array
    {
        $iso = $date->toDateString();
        $isPast = $date->copy()->startOfDay()->lt(Carbon::today());

        $rooms = Room::query()
            ->where('status', RoomStatus::Active->value)
            ->when($this->currentPropertyId(), fn ($q, $pid) => $q->where('property_id', $pid))
            ->with('category:id,name,base_rate,breakfast_included_rate')
            ->orderBy('room_number')
            ->get();

        // For PAST days include CheckedOut + NoShow so history is
        // preserved - a guest who stayed on Sep 4 and checked out
        // on Sep 5 should still appear in Sep 4's cell as "Occupied".
        // Cancelled is always excluded because a cancelled booking
        // means the stay never happened.
        $excludeStatuses = $isPast
            ? [BookingStatus::Cancelled->value]
            : [
                BookingStatus::Cancelled->value,
                BookingStatus::CheckedOut->value,
                BookingStatus::NoShow->value,
            ];

        $bookings = Booking::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->whereNotIn('booking_status', $excludeStatuses)
            ->whereDate('checkin_date', '<=', $iso)
            ->whereDate('checkout_date', '>', $iso)
            ->with(['customer', 'corporate'])
            ->get()
            ->keyBy('room_id');

        $blocks = AvailabilityBlock::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->where('type', AvailabilityBlockType::Blocked->value)
            ->whereDate('date', $iso)
            ->get()
            ->keyBy('room_id');

        $rows = $rooms->map(function (Room $r) use ($iso, $bookings, $blocks, $isPast) {
            $booking = $bookings->get($r->id);
            $block = $blocks->get($r->id);

            // Past days read as history ("Occupied", "No-show") not
            // as live state ("Arriving"). Today + future keep the
            // existing arriving / in_house / departing semantics.
            $status = $isPast ? 'past_free' : 'free';
            if ($booking !== null) {
                $isCheckedIn = $booking->booking_status === BookingStatus::CheckedIn;
                $isCheckedOut = $booking->booking_status === BookingStatus::CheckedOut;
                $isNoShow = $booking->booking_status === BookingStatus::NoShow;
                $departsToday = $booking->checkout_date
                    && $booking->checkout_date->toDateString() === $iso;

                if ($isPast) {
                    // NoShow enum trumps - an explicit NoShow is
                    // unambiguous even if the guest was briefly
                    // CheckedIn on the row's booking.
                    if ($isNoShow) {
                        $status = 'no_show';
                    } else {
                        $status = ($isCheckedIn || $isCheckedOut) ? 'occupied' : 'no_show';
                    }
                } else {
                    if ($isCheckedIn) {
                        $status = $departsToday ? 'departing' : 'in_house';
                    } else {
                        $status = 'arriving';
                    }
                }
            } elseif ($block !== null) {
                $status = 'blocked';
            }

            return [
                'room'    => $r,
                'status'  => $status,
                'booking' => $booking,
                'block'   => $block,
            ];
        });

        // Category-only (unassigned) bookings that overlap this
        // day. They have no room_id so the room-keyed rows above
        // miss them entirely - the year cell still shades amber
        // (via CalendarHeatmap counting category-level blocks) but
        // the day-detail modal used to show nothing for them.
        $unassignedForDay = Booking::query()
            ->with(['roomCategory:id,name,property_id,base_rate', 'customer'])
            ->whereNull('room_id')
            ->whereNotNull('room_category_id')
            ->whereNotIn('booking_status', $excludeStatuses)
            ->whereDate('checkin_date', '<=', $iso)
            ->whereDate('checkout_date', '>', $iso)
            ->when($this->currentPropertyId(), fn ($q, $pid) => $q->whereHas(
                'roomCategory', fn ($c) => $c->where('property_id', $pid),
            ))
            ->orderBy('checkin_date')
            ->orderBy('guest_name')
            ->get();

        $countBy = fn (string $status) => $rows->where('status', $status)->count();

        $arrivalsToday = $rows->filter(fn ($r) => $r['booking']
            && $r['booking']->checkin_date
            && $r['booking']->checkin_date->toDateString() === $iso
        )->count();

        // On past days 'departing' never fires (that label is today-only),
        // so derive departures from checkout_date == iso against the
        // historical booking set instead.
        $departuresCount = $isPast
            ? $rows->filter(fn ($r) => $r['booking']
                && $r['booking']->checkout_date
                && $r['booking']->checkout_date->toDateString() === $iso
            )->count()
            : $countBy('departing');

        return [
            'date'    => $date,
            'is_past' => $isPast,
            'rooms'   => $rows,
            'unassigned' => $unassignedForDay,
            'kpi'     => [
                'arrivals'   => $arrivalsToday,
                'departures' => $departuresCount,
                // On past days "in_house" reads as who was here - the
                // occupied count. On today/future it's the live sum.
                'in_house'   => $isPast
                    ? $countBy('occupied')
                    : $countBy('in_house') + $countBy('departing'),
                'blocked'    => $countBy('blocked'),
                'free'       => $isPast ? 0 : $countBy('free'),
                'unassigned' => $unassignedForDay->count(),
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Booking>
     */
    private function searchResults()
    {
        $q = trim($this->search);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        $like = '%'.$q.'%';

        return Booking::query()
            ->with(['room:id,room_number', 'customer:id,name,phone,email'])
            ->when($this->currentPropertyId(), fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->where(function ($query) use ($like) {
                $query->where('booking_ref', 'like', $like)
                    ->orWhere('guest_name', 'like', $like)
                    ->orWhere('guest_phone', 'like', $like)
                    ->orWhere('guest_email', 'like', $like);
            })
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();
    }

    /**
     * @return array{
     *     checkouts_today: int, checkouts_tomorrow: int, overdue: int,
     *     total_accrued: int, top: \Illuminate\Support\Collection<int, Booking>,
     * }
     */
    private function buildInHouseSheetData(Carbon $today, Carbon $tomorrow): array
    {
        // `room.category.base_rate` is on the eager-load column list
        // because outstandingTotal() → unchargedOverstayAmount()
        // reads $room->base_rate - which is an accessor that pulls
        // from category->base_rate. Skipping the column zeroed
        // overstay for every overdue guest, so total_accrued read
        // ₦0 in the sheet header even when the per-row list
        // clearly showed ₦60k owed. `charges` is loaded so the
        // unsettled-charge sum doesn't trigger N+1 lookups.
        $inHouse = Booking::query()
            ->with(['room:id,room_number,room_category_id', 'room.category:id,name,base_rate', 'payments', 'charges'])
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->when($this->currentPropertyId(), fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->orderBy('checkout_date')
            ->orderBy('guest_name')
            ->get();

        $top = $inHouse->take(5)->values();

        // Client-side panel payload keyed by booking id. The in-house
        // sheet's rows dispatch `inhouse-sheet-panel-open` with an id
        // and the shared Alpine panel opens without touching the
        // sheet's own `open` state - sheet stays visible behind the
        // panel so the operator can flip between guests.
        $now = Carbon::now();
        $panels = $top->mapWithKeys(function (Booking $b) use ($today, $now): array {
            $isOverdue = $b->checkout_date && $b->checkout_date->lt($today);
            $lateProjection = $b->projectedLateCheckoutFee($now);
            $isSameDayLate = $lateProjection['kind'] !== 'none';
            $payments = $b->payments
                ->sortByDesc('paid_at')
                ->take(3)
                ->map(fn ($p) => [
                    'amount' => (int) $p->amount,
                    'method' => ucfirst((string) ($p->method ?? 'paystack')),
                    'paid_at' => $p->paid_at?->format('j M · H:i') ?? '-',
                ])->values()->all();
            return [$b->id => [
                'id' => $b->id,
                'ref' => (string) $b->booking_ref,
                'guest_name' => (string) $b->guest_name,
                'guest_phone' => (string) ($b->guest_phone ?? ''),
                'guest_email' => (string) ($b->guest_email ?? ''),
                'room' => $b->room?->room_number ?? '-',
                'category' => (string) ($b->room?->category?->name ?? ''),
                'checkin' => $b->checkin_date?->format('D, j M Y') ?? '-',
                'checkout' => $b->checkout_date?->format('D, j M Y') ?? '-',
                'nights' => $b->checkin_date && $b->checkout_date
                    ? max(0, (int) $b->checkin_date->diffInDays($b->checkout_date))
                    : 0,
                'is_overdue' => (bool) $isOverdue,
                'is_same_day_late' => $isSameDayLate,
                'late_fee_projected' => (int) $lateProjection['amount'],
                'late_description' => (string) $lateProjection['description'],
                'total' => (int) ($b->total_amount ?? 0),
                'deposit' => (int) ($b->deposit_amount ?? 0),
                'balance' => (int) ($b->balance_due ?? 0),
                'outstanding' => (int) $b->outstandingTotal(),
                'payments' => $payments,
                'url' => route('tenant.reservations.show', $b->booking_ref),
            ]];
        })->all();

        return [
            'checkouts_today'    => $inHouse->filter(
                fn (Booking $b) => $b->checkout_date && $b->checkout_date->isSameDay($today),
            )->count(),
            'checkouts_tomorrow' => $inHouse->filter(
                fn (Booking $b) => $b->checkout_date && $b->checkout_date->isSameDay($tomorrow),
            )->count(),
            'overdue' => $inHouse->filter(
                fn (Booking $b) => $b->checkout_date && $b->checkout_date->lt($today),
            )->count(),
            // Also count same-day late guests so the header
            // surfaces "3 late" alongside overdue - the two are
            // distinct: overdue = past checkout_date, late = today
            // is checkout but past the check-out cutoff time.
            'same_day_late' => $inHouse->filter(fn (Booking $b) => $b->isSameDayLate($now))->count(),
            'total_accrued' => (int) $inHouse->sum(fn (Booking $b) => $b->outstandingTotal()),
            'top' => $top,
            'panels' => $panels,
        ];
    }

    private function currentOperator(): ?Operator
    {
        $op = auth('web')->user();

        return $op instanceof Operator ? $op : null;
    }

    /**
     * The property id the switcher has selected. null when no property
     * is resolvable (unseeded tenant, cross-boundary requests). Used
     * with ->when() so a null value leaves the query unscoped -
     * important because a null falls through to "show nothing" would
     * be worse than "show all" for the pre-multi-branch case.
     */
    private function currentPropertyId(): ?int
    {
        return app(PropertyContext::class)->current()?->getKey();
    }
}
