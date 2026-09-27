<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Models\Activity;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * One-shot data bundle for the operator dashboard home (PRD §6.2.1):
 *
 *   - Today's arrivals (Confirmed/OnHold bookings with checkin_date == today)
 *   - Today's departures (CheckedIn bookings with checkout_date == today)
 *   - 14-day availability mini-heatmap (rooms × dates)
 *   - Outstanding balances (OnHold bookings with balance_due > 0)
 *   - Recent bookings (latest 5 by id)
 *   - Rolling-30-day occupancy %
 *
 * Cached for 60 seconds per tenant - dashboard refresh is a hot path during
 * busy mornings, and these queries hit several tables. Cache key embeds the
 * tenant id so two operators on different tenants never see each other's data.
 *
 * Pass `$bypass=true` to skip the cache (used by integration tests or after
 * an in-session booking action so the operator sees their just-made change).
 */
class DashboardSnapshot
{
    public const CACHE_TTL_SECONDS = 60;

    public const HEATMAP_DAYS = 14;

    public const RECENT_BOOKINGS_LIMIT = 5;

    /**
     * Instance-scoped property id - set at build() time based on the
     * operator's currently-picked branch. null in tests + legacy
     * pre-multi-property tenants. Every private query method reads
     * through here so the same snapshot layer serves both cases
     * without threading the id through every signature.
     */
    private ?int $propertyId = null;

    public function __construct(private readonly OccupancyCalculator $occupancy) {}

    /**
     * @return array{
     *     today: array{date: string, arrivals: Collection<int, Booking>, departures: Collection<int, Booking>, inHouse: Collection<int, Booking>},
     *     occupancy: array<string, int|string>,
     *     heatmap: array{rooms: Collection<int, Room>, dates: list<string>, blocks: array<int, array<string, string>>},
     *     outstanding: Collection<int, Booking>,
     *     recent: Collection<int, Booking>,
     * }
     */
    public function build(bool $bypass = false): array
    {
        $tenant = Tenant::current();
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : 0;
        // Resolve + stash the operator's picked branch so every private
        // compute() helper filters by it. Cache key includes the id so
        // two operators looking at different branches don't step on
        // each other's snapshot.
        $this->propertyId = app(\App\Services\Properties\PropertyContext::class)->current()?->getKey();
        $propertyKey = $this->propertyId ?? 'all';
        $cacheKey = "dashboard:snapshot:t{$tenantId}:p{$propertyKey}";

        $compute = fn (): array => $this->compute();

        return $bypass
            ? $compute()
            : Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, $compute);
    }

    /**
     * Drop the cached snapshot for the current tenant. Call from action layer
     * after a state change (cancel, check-in, …) so the next dashboard hit
     * sees fresh numbers without waiting out the 60s TTL.
     *
     * Wildcards across all branches for the tenant - a booking cancelled
     * in Lagos still nudges Abuja's aggregate if we ever expose an
     * "all branches" toggle in future.
     */
    public static function forget(): void
    {
        $tenant = Tenant::current();
        if (! $tenant instanceof Tenant) {
            return;
        }
        $tenantId = $tenant->getKey();
        // Bust the shared "no branch" key + a bust per known property
        // id so branch-scoped caches don't linger with stale numbers.
        Cache::forget("dashboard:snapshot:t{$tenantId}:pall");
        foreach ($tenant->properties()->pluck('id') as $pid) {
            Cache::forget("dashboard:snapshot:t{$tenantId}:p{$pid}");
        }
    }

    /**
     * @return array{
     *     today: array{date: string, arrivals: Collection<int, Booking>, departures: Collection<int, Booking>, inHouse: Collection<int, Booking>},
     *     occupancy: array<string, int|string>,
     *     heatmap: array{rooms: Collection<int, Room>, dates: list<string>, blocks: array<int, array<string, string>>},
     *     outstanding: Collection<int, Booking>,
     *     recent: Collection<int, Booking>,
     * }
     */
    /**
     * Applies the current-branch filter to a Booking / Payment /
     * BookingCharge query. No-op when $this->propertyId is null
     * (single-branch tenant OR pre-multi context), which keeps the
     * query result identical to the pre-multi-property behavior.
     */
    private function scopeBookings(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pid = $this->propertyId;
        return $pid === null
            ? $q
            : $q->whereHas('room', fn ($r) => $r->where('property_id', $pid));
    }

    private function scopeByBooking(\Illuminate\Database\Eloquent\Builder $q, string $relation = 'booking.room'): \Illuminate\Database\Eloquent\Builder
    {
        $pid = $this->propertyId;
        return $pid === null
            ? $q
            : $q->whereHas($relation, fn ($r) => $r->where('property_id', $pid));
    }

    private function scopeRooms(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pid = $this->propertyId;
        return $pid === null
            ? $q
            : $q->where('property_id', $pid);
    }

    private function compute(): array
    {
        $today = Carbon::today();
        $todayStr = $today->toDateString();

        $arrivals = $this->scopeBookings(Booking::query())
            ->with('room')
            ->whereIn('booking_status', [BookingStatus::Confirmed, BookingStatus::OnHold])
            ->whereDate('checkin_date', $todayStr)
            ->orderBy('checkin_date')
            ->get();

        // Category-only (unassigned) reservations arriving today.
        // scopeBookings uses whereHas('room', ...) so a room_id=NULL
        // booking would silently drop off the arrivals list, and the
        // Pending status also gets stripped by the Confirmed/OnHold
        // filter above. Query them separately so the operator sees
        // the "assign a room" work item on the dashboard.
        $pid = $this->propertyId;
        $unassignedArrivals = Booking::query()
            ->with(['roomCategory:id,name,property_id,base_rate', 'customer'])
            ->whereNull('room_id')
            ->whereNotNull('room_category_id')
            ->whereIn('booking_status', [
                BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold,
            ])
            ->whereDate('checkin_date', $todayStr)
            ->when($pid, fn ($q, $pid) => $q->whereHas(
                'roomCategory', fn ($c) => $c->where('property_id', $pid),
            ))
            ->orderBy('checkin_date')
            ->orderBy('guest_name')
            ->get();

        // Every unassigned reservation across the horizon - the
        // dashboard shows a "waiting on assignment" count so the
        // front desk knows how many work items are outstanding
        // regardless of arrival date.
        $unassignedTotal = Booking::query()
            ->whereNull('room_id')
            ->whereNotNull('room_category_id')
            ->whereIn('booking_status', [
                BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold,
            ])
            ->when($pid, fn ($q, $pid) => $q->whereHas(
                'roomCategory', fn ($c) => $c->where('property_id', $pid),
            ))
            ->count();

        $departures = $this->scopeBookings(Booking::query())
            ->with('room')
            ->where('booking_status', BookingStatus::CheckedIn)
            ->whereDate('checkout_date', $todayStr)
            ->orderBy('checkout_date')
            ->get();

        // Every guest currently in-house, regardless of their booked
        // checkout date. Powers the dashboard "Check out" panel so the
        // front desk can surface early departures too - a guest who
        // booked five nights but wants to leave tonight would never
        // appear in $departures (which is scoped to today's booked
        // checkouts) even though they're standing at the counter.
        $inHouse = $this->scopeBookings(Booking::query())
            ->with('room')
            ->where('booking_status', BookingStatus::CheckedIn)
            ->orderBy('checkout_date')
            ->orderBy('guest_name')
            ->get();

        $heatmapEnd = $today->copy()->addDays(self::HEATMAP_DAYS - 1);
        $heatmapDates = [];
        for ($cur = $today->copy(); $cur->lte($heatmapEnd); $cur->addDay()) {
            $heatmapDates[] = $cur->toDateString();
        }

        $rooms = $this->scopeRooms(Room::query())
            ->with('category')
            ->where('status', RoomStatus::Active->value)
            ->orderBy('room_category_id')
            ->orderBy('room_number')
            ->get();

        // Pre-fetch all blocks in window into a [room_id][date] map for O(1) lookup
        // in the view (avoids N×14 queries when rendering the grid).
        // We carry booking_id alongside the type so the interactive
        // heatmap can deep-link a booked cell into its reservation
        // sheet (matching the full property calendar behaviour).
        $blocks = [];
        $blockBookings = [];
        $bookingIdsTouched = [];
        AvailabilityBlock::query()
            ->whereIn('room_id', $rooms->pluck('id')->all())
            ->whereDate('date', '>=', $todayStr)
            ->whereDate('date', '<=', $heatmapEnd->toDateString())
            ->get(['room_id', 'date', 'type', 'booking_id'])
            ->each(function (AvailabilityBlock $b) use (&$blocks, &$blockBookings, &$bookingIdsTouched): void {
                $dateKey = $b->date->toDateString();
                $blocks[$b->room_id][$dateKey] = $b->type === AvailabilityBlockType::Booked
                    ? 'booked'
                    : 'blocked';
                if ($b->booking_id !== null) {
                    $blockBookings[$b->room_id][$dateKey] = $b->booking_id;
                    $bookingIdsTouched[$b->booking_id] = true;
                }
            });

        // Re-label cells whose underlying booking is still Pending so
        // the heatmap can render holds distinctly from confirmed sales.
        if ($bookingIdsTouched !== []) {
            $pendingIds = $this->scopeBookings(Booking::query())
                ->whereIn('id', array_keys($bookingIdsTouched))
                ->where('booking_status', BookingStatus::Pending->value)
                ->pluck('id')
                ->all();
            $pendingIds = array_fill_keys($pendingIds, true);
            foreach ($blockBookings as $roomId => $cells) {
                foreach ($cells as $date => $bookingId) {
                    if (isset($pendingIds[$bookingId])) {
                        $blocks[$roomId][$date] = 'pending';
                    }
                }
            }

            // Currently CheckedIn bookings get colour-coded by whether
            // checkout is today (due out) or overdue (past checkout).
            // Matches the property-calendar convention so an operator's
            // eye reads the same signal on any grid.
            $checkedIn = $this->scopeBookings(Booking::query())
                ->whereIn('id', array_keys($bookingIdsTouched))
                ->where('booking_status', BookingStatus::CheckedIn->value)
                ->get(['id', 'checkout_date'])
                ->keyBy('id');

            if ($checkedIn->isNotEmpty()) {
                foreach ($blockBookings as $roomId => $cells) {
                    foreach ($cells as $date => $bookingId) {
                        $booking = $checkedIn->get($bookingId);
                        if ($booking === null) {
                            continue;
                        }
                        $checkoutIso = $booking->checkout_date?->toDateString();
                        if ($checkoutIso === null) {
                            continue;
                        }
                        if ($todayStr > $checkoutIso) {
                            $blocks[$roomId][$date] = 'overdue';
                        } elseif ($todayStr === $checkoutIso) {
                            $blocks[$roomId][$date] = 'due_out';
                        } else {
                            $blocks[$roomId][$date] = 'checkedin';
                        }
                    }
                }
            }
        }

        // Checkout-day + post-checkout overlay for CheckedIn guests.
        // AvailabilityBlocks are [checkin, checkout), so the checkout
        // day itself carries no block - but the guest is still in the
        // room until MarkCheckedOut runs. Without this overlay the
        // heatmap paints today as free and lets the operator try to
        // book on top of the occupant. Mirrors the same pass in
        // PropertyView so both grids read consistently.
        $activeCheckins = $this->scopeBookings(Booking::query())
            ->whereIn('room_id', $rooms->pluck('id')->all())
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereNotNull('checkout_date')
            ->whereDate('checkout_date', '<=', $todayStr)
            ->get(['id', 'room_id', 'checkout_date']);
        foreach ($activeCheckins as $booking) {
            $checkoutDate = $booking->checkout_date->copy()->startOfDay();
            $overlayStart = $checkoutDate->greaterThan($today) ? $checkoutDate : $today->copy();
            $overlayEnd   = $today->copy();   // heatmap is today→+14, past cells never render
            for ($c = $overlayStart->copy(); $c->lessThanOrEqualTo($overlayEnd); $c->addDay()) {
                $iso = $c->toDateString();
                if (! in_array($iso, $heatmapDates, true)) {
                    continue;
                }
                if (isset($blocks[$booking->room_id][$iso])) {
                    continue;   // real block wins
                }
                $blocks[$booking->room_id][$iso] = $iso === $todayStr
                    ? ($checkoutDate->lessThan($today) ? 'overdue' : 'due_out')
                    : 'overdue';
                $blockBookings[$booking->room_id][$iso] = $booking->id;
            }
        }

        // "Outstanding balances" widget - a booking is outstanding if
        // it still owes the platform money via one of:
        //   a. Leftover balance_due (deposit paid, balance-at-check-in)
        //   b. One or more unsettled BookingCharges (mid-stay extras)
        //   c. Currently CheckedIn but past checkout_date without an
        //      Overstay charge yet (the guest is racking up projected
        //      overstay fees the operator hasn't formalised)
        // Missing (c) meant an overstaying guest looked ₦0 outstanding
        // until formal checkout, which hid the running exposure.
        // Eager-load `charges` so the widget can render the unsettled-
        // count hint + outstandingTotal() without N+1.
        $outstanding = $this->scopeBookings(Booking::query())
            ->with(['room', 'charges', 'corporate:id,name'])
            // OnHold / CheckedIn is the original in-house bucket.
            // CheckedOut is also included when the booking is corp-
            // guaranteed AND still uninvoiced — those still owe
            // money (corp bills after the fact) and the operator
            // has to see them somewhere to raise the invoice.
            ->where(function ($top) use ($todayStr): void {
                $top->where(function ($q) use ($todayStr): void {
                    $q->whereIn('booking_status', [BookingStatus::OnHold, BookingStatus::CheckedIn])
                        ->where(function ($qq) use ($todayStr): void {
                            $qq->where('balance_due', '>', 0)
                                ->orWhereExists(function ($sub): void {
                                    $sub->from('booking_charges')
                                        ->whereColumn('booking_charges.booking_id', 'bookings.id')
                                        ->where('booking_charges.settled', false);
                                })
                                ->orWhere(function ($qqq) use ($todayStr): void {
                                    $qqq->where('booking_status', BookingStatus::CheckedIn->value)
                                        ->whereDate('checkout_date', '<', $todayStr)
                                        ->whereNotExists(function ($sub): void {
                                            $sub->from('booking_charges')
                                                ->whereColumn('booking_charges.booking_id', 'bookings.id')
                                                ->where('type', BookingChargeType::Overstay->value);
                                        });
                                });
                        });
                })->orWhere(function ($q): void {
                    // Also exclude bookings that a *consolidated* corp
                    // invoice covers via InvoiceLine.source_id — those
                    // invoices have booking_id=NULL so the direct
                    // ->invoices() relation misses them.
                    $q->where('booking_status', BookingStatus::CheckedOut->value)
                        ->whereNotNull('corporate_id')
                        ->where('balance_due', '>', 0)
                        ->whereDoesntHave('invoices', function ($sub): void {
                            $sub->whereIn('status', ['issued', 'partial', 'paid']);
                        })
                        ->whereNotExists(function ($sub): void {
                            $sub->from('invoice_lines')
                                ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                                ->whereColumn('invoice_lines.source_id', 'bookings.id')
                                ->where('invoice_lines.source_type', Booking::class)
                                ->whereIn('invoices.status', ['issued', 'partial', 'paid']);
                        });
                });
            })
            ->orderByRaw('COALESCE(balance_due_at, checkin_date) asc')
            ->limit(10)
            ->get();

        $recent = $this->scopeBookings(Booking::query())
            ->with('room')
            ->latest('id')
            ->limit(self::RECENT_BOOKINGS_LIMIT)
            ->get();

        // ── Command-centre status pulse ─────────────────────────────
        // One row of "what's happening right now" - the four numbers a
        // manager wants to see the instant the page loads. Everything
        // else on the dashboard is derived context.
        $pulseInHouse = $this->scopeBookings(Booking::query())
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->count();

        // ── Recent activity feed ────────────────────────────────────
        // Last 8 booking / inspection events for the manager's "what
        // has been happening in the building?" scan. Uses the Spatie
        // activity log (populated by MarkCheckedIn, MarkCheckedOut,
        // RecordManualPayment, SubmitHousekeeperCleaning, etc.). Cheap
        // - one indexed query on the activity_log table.
        // Uses App\Models\Activity, not Spatie's base class - the app model
        // applies a tenant global scope so operators never see another
        // tenant's audit rows on the dashboard.
        $activity = Activity::query()
            ->with('causer')
            ->whereIn('log_name', ['booking', 'inspection', 'housekeeping'])
            ->latest('id')
            ->limit(8)
            ->get();

        // ── KPI strip + comparisons ─────────────────────────────────
        $kpis = $this->kpis($today);

        // ── Sparkline data: last 30 days of realised revenue ────────
        $spark = $this->revenueSparkline($today);
        // ── Month-to-date daily chart data ──────────────────────────
        $monthChart = $this->monthRevenueBars($today);

        // ── Today's bookings by category (stacked bar) ──────────────
        $todayMix = $this->todayCategoryMix($today);

        // ── Needs Attention queue ───────────────────────────────────
        $needsAttention = $this->needsAttention($today, $outstanding, $arrivals);

        return [
            'today' => [
                'date' => $todayStr,
                'arrivals' => $arrivals,
                'departures' => $departures,
                'inHouse' => $inHouse,
                // Category-only reservations arriving today - the
                // "assign a room + confirm & pay" work items the
                // front desk needs to see on the home page.
                'unassignedArrivals' => $unassignedArrivals,
            ],
            'occupancy' => $this->occupancy->rolling(),
            'kpis' => $kpis,
            'spark' => $spark,
            'monthChart' => $monthChart,
            'todayMix' => $todayMix,
            'needsAttention' => $needsAttention,
            'pulse' => [
                'in_house'    => $pulseInHouse,
                'arriving'    => $arrivals->count() + $unassignedArrivals->count(),
                'departing'   => $departures->count(),
                'outstanding' => (int) ($kpis['outstanding']['value'] ?? 0),
                'unassigned'  => $unassignedTotal,
                'unassigned_today' => $unassignedArrivals->count(),
            ],
            'activity' => $activity,
            'heatmap' => [
                'rooms' => $rooms,
                'dates' => $heatmapDates,
                'blocks' => $blocks,
                'bookings' => $blockBookings,
                // Category-grouped view of the same rooms - drives the
                // section headers in the dashboard mini-heatmap. Each
                // group: { name, type, rooms[] }. Rooms without a
                // category fall under an "Uncategorised" pseudo-group.
                'grouped' => $rooms
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
                    ->values(),
            ],
            'outstanding' => $outstanding,
            'recent' => $recent,
        ];
    }

    /**
     * Four headline KPIs with trend arrows vs the previous comparable
     * window. Trends are computed as percent change with the absolute
     * delta available so the view can show direction + magnitude.
     *
     * @return array{
     *     occupancy: array{value: int, delta_pct: float, direction: string, label: string},
     *     revenue_mtd: array{value: int, delta_pct: float, direction: string, label: string},
     *     arrivals_today: array{value: int, delta_pct: float, direction: string, label: string},
     *     outstanding: array{value: int, delta_pct: float, direction: string, label: string},
     * }
     */
    private function kpis(Carbon $today): array
    {
        // 1. Occupancy now vs same window last month.
        $occNow = (int) ($this->occupancy->rolling()['percent'] ?? 0);
        $occPrev = $this->priorOccupancyPercent($today);

        // 2. Revenue MTD - cash collected minus refunds in the current
        //    month (Payment.amount by paid_at). Was accrual (bookings
        //    created this month × total_amount) which disagreed with
        //    the Payments page and the Summary total whenever guests
        //    paid across month boundaries or bookings got cancelled
        //    after payment. One consistent cash-basis definition
        //    across Dashboard, Summary, and Payments.
        //    Same-days-in-prior-month window so a mid-month visit
        //    doesn't look 50% worse than a full prior month.
        $monthStart = $today->copy()->startOfMonth();
        $daysIn = (int) max(1, $today->diffInDays($monthStart) + 1);

        $revNow = max(0,
            (int) $this->scopeByBooking(Payment::query())
                ->where('status', PaymentStatus::Succeeded->value)
                ->whereBetween('paid_at', [$monthStart->copy()->startOfDay(), $today->copy()->endOfDay()])
                ->sum('amount')
            - (int) $this->scopeByBooking(Payment::query())
                ->where('status', PaymentStatus::Refunded->value)
                ->whereBetween('paid_at', [$monthStart->copy()->startOfDay(), $today->copy()->endOfDay()])
                ->sum('amount')
        );

        $priorStart = $monthStart->copy()->subMonth();
        $priorEnd = $priorStart->copy()->addDays($daysIn - 1);
        $revPrev = max(0,
            (int) $this->scopeByBooking(Payment::query())
                ->where('status', PaymentStatus::Succeeded->value)
                ->whereBetween('paid_at', [$priorStart->startOfDay(), $priorEnd->endOfDay()])
                ->sum('amount')
            - (int) $this->scopeByBooking(Payment::query())
                ->where('status', PaymentStatus::Refunded->value)
                ->whereBetween('paid_at', [$priorStart->startOfDay(), $priorEnd->endOfDay()])
                ->sum('amount')
        );

        // 3. Today's arrivals vs same weekday last week.
        $arrivalsNow = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', [BookingStatus::Confirmed, BookingStatus::OnHold])
            ->whereDate('checkin_date', $today->toDateString())
            ->count();
        $arrivalsPrev = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', [BookingStatus::Confirmed, BookingStatus::OnHold])
            ->whereDate('checkin_date', $today->copy()->subWeek()->toDateString())
            ->count();

        // 4. Outstanding ₦ - everything the guest still owes across
        //    every inventory-holding booking. Delegates to each
        //    booking's own {@see Booking::outstandingTotal()} so the
        //    number matches the In-house sheet exactly. The old
        //    aggregate-SQL approach used a whereNotExists guard that
        //    zeroed out projected overstay whenever ANY Overstay
        //    charge existed on the row - even a partial one - so a
        //    guest that had been billed for one overstay night and
        //    was still racking up a second dropped out of the KPI
        //    while the In-house page kept counting them. Summing the
        //    canonical per-booking helper drops that N+1 concern for
        //    typical fleet sizes (~50 open bookings max) and keeps
        //    the two surfaces in lockstep.
        $openStatuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::OnHold->value,
            BookingStatus::CheckedIn->value,
        ];
        $outstandingNow = (int) $this->scopeBookings(Booking::query())
            ->with('charges')
            ->whereIn('booking_status', $openStatuses)
            ->get()
            ->sum(fn (Booking $b) => $b->outstandingTotal());

        // CheckedOut corporate-guaranteed bookings still owe money —
        // the corp bills by invoice after the fact. Their balance
        // was excluded from the pulse until the invoice landed, which
        // meant the outstanding tile went to ₦0 the moment a corp
        // guest walked out even though the corp hadn't paid yet.
        // Include them until an Issued/Paid invoice covers the row.
        $outstandingNow += (int) $this->scopeBookings(Booking::query())
            ->with('charges')
            ->where('booking_status', BookingStatus::CheckedOut->value)
            ->whereNotNull('corporate_id')
            ->whereDoesntHave('invoices', function ($q): void {
                $q->whereIn('status', ['issued', 'partial', 'paid']);
            })
            ->whereNotExists(function ($sub): void {
                $sub->from('invoice_lines')
                    ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                    ->whereColumn('invoice_lines.source_id', 'bookings.id')
                    ->where('invoice_lines.source_type', Booking::class)
                    ->whereIn('invoices.status', ['issued', 'partial', 'paid']);
            })
            ->get()
            ->sum(fn (Booking $b) => $b->outstandingTotal());

        return [
            'occupancy' => $this->trend($occNow, $occPrev, '30-day occupancy'),
            'revenue_mtd' => $this->trend($revNow, $revPrev, 'Revenue MTD'),
            'arrivals_today' => $this->trend($arrivalsNow, $arrivalsPrev, 'Arrivals today'),
            'outstanding' => $this->trend($outstandingNow, 0, 'Outstanding'),
        ];
    }

    /**
     * Last 30 days of confirmed-or-checked-in revenue + a stay-count
     * sparkline. Returned as parallel arrays so the SVG renderer can
     * iterate in one pass.
     *
     * @return array{points: list<int>, labels: list<string>, total: int, max: int}
     */
    private function revenueSparkline(Carbon $today): array
    {
        $start = $today->copy()->subDays(29);

        // Daily cash-in (succeeded minus refunded) by paid_at so the
        // sparkline reads the same as the Revenue MTD KPI above and
        // reconciles with the Payments page for any single day.
        $rows = $this->scopeByBooking(Payment::query())
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
            ->whereBetween('paid_at', [$start->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->get(['paid_at', 'amount', 'status']);

        $byDate = [];
        for ($c = $start->copy(); $c->lte($today); $c->addDay()) {
            $byDate[$c->toDateString()] = 0;
        }

        foreach ($rows as $row) {
            $key = $row->paid_at?->toDateString();
            if (! $key || ! isset($byDate[$key])) continue;
            $signed = $row->status === PaymentStatus::Refunded
                ? -1 * (int) $row->amount
                : (int) $row->amount;
            $byDate[$key] += $signed;
        }

        // Header total is the NET across the window - sum the raw
        // per-day nets (which can be negative) then max-with-0 so
        // the tile never reads below zero. Same fix as monthRevenueBars:
        // if we summed the clamped points array below, a pure-refund
        // day (net < 0) would be treated as 0 and the total would
        // over-report the actual cash retained.
        $total = max(0, array_sum($byDate));

        // Now clamp per-day for the chart display only - keeps the
        // line from dipping under the x-axis on rare refund-only days.
        $points = [];
        foreach ($byDate as $v) {
            $points[] = max(0, $v);
        }
        $max = max(1, max($points) ?: 1);

        $labels = [];
        foreach (array_keys($byDate) as $d) {
            $labels[] = Carbon::parse($d)->format('d M');
        }

        return [
            'points' => $points,
            'labels' => $labels,
            'total' => $total,
            'max' => $max,
        ];
    }

    /**
     * Today's inventory split - how many room-nights per category are
     * sold vs available. Drives the horizontal stacked bar.
     *
     * @return array{rows: list<array{name: string, sold: int, total: int}>}
     */
    /**
     * Line-chart data for the current month: one point per day from
     * the 1st to the last day of the month. Days at or before today
     * carry the realised cash-in (succeeded minus refunded by
     * paid_at); days after today carry 0 so the line drops back to
     * the axis and the chart width is stable for the whole month.
     *
     * `today_index` tells the view where to place the "today"
     * marker (blinking circle) even when there's no revenue on
     * that day.
     *
     * @return array{
     *   points: list<int>, labels: list<string>, days: list<int>,
     *   iso: list<string>, total: int, max: int, prev_total: int,
     *   delta_pct: float|null, month_label: string,
     *   today_index: int, first_day: string, last_day: string,
     * }
     */
    private function monthRevenueBars(Carbon $today): array
    {
        $start = $today->copy()->startOfMonth();
        $end = $today->copy()->endOfMonth();

        $rows = $this->scopeByBooking(Payment::query())
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
            ->whereBetween('paid_at', [$start->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->get(['paid_at', 'amount', 'status']);

        // Track raw daily net (can be negative when a day's refunds
        // exceed same-day collections). Compute the total from these
        // raw numbers, then clamp per-day values to 0 ONLY for the
        // display bars - otherwise summing clamped values eats the
        // refund impact whenever refunds fell on a day without
        // matching income (which is why the header stayed at gross
        // even after refunds landed).
        $byDate = [];
        for ($c = $start->copy(); $c->lte($end); $c->addDay()) {
            $byDate[$c->toDateString()] = 0;
        }
        foreach ($rows as $r) {
            $key = $r->paid_at?->toDateString();
            if (! $key || ! isset($byDate[$key])) continue;
            $signed = $r->status === PaymentStatus::Refunded
                ? -1 * (int) $r->amount
                : (int) $r->amount;
            $byDate[$key] += $signed;
        }
        $labels = array_map(fn (string $iso) => Carbon::parse($iso)->format('D, j M'), array_keys($byDate));
        $days = array_map(fn (string $iso) => (int) Carbon::parse($iso)->day, array_keys($byDate));
        $iso = array_keys($byDate);
        // Header total = raw net across the window, floor at 0.
        $total = max(0, array_sum($byDate));
        // Points for the chart = per-day net, floored at 0 (a bar
        // can't have negative height and there's no y-axis below 0).
        $points = array_map(fn (int $v) => max(0, $v), array_values($byDate));
        $max = count($points) > 0 ? max($points) : 0;
        $todayIndex = (int) $today->day - 1;   // 0-based

        // Prior-month same-days comparison so the delta ratio is
        // fair mid-month (e.g. today = Sep 5 → compares Sep 1-5 vs
        // Aug 1-5, not Sep 1-5 vs entire August).
        $daysIn = (int) $today->day;
        $prevStart = $start->copy()->subMonth();
        $prevEnd = $prevStart->copy()->addDays(max(0, $daysIn - 1));
        $prevTotal = max(0,
            (int) $this->scopeByBooking(Payment::query())
                ->where('status', PaymentStatus::Succeeded->value)
                ->whereBetween('paid_at', [$prevStart->copy()->startOfDay(), $prevEnd->copy()->endOfDay()])
                ->sum('amount')
            - (int) $this->scopeByBooking(Payment::query())
                ->where('status', PaymentStatus::Refunded->value)
                ->whereBetween('paid_at', [$prevStart->copy()->startOfDay(), $prevEnd->copy()->endOfDay()])
                ->sum('amount')
        );
        $deltaPct = $prevTotal > 0
            ? round((($total - $prevTotal) / $prevTotal) * 100, 1)
            : null;

        return [
            'points' => $points,
            'labels' => $labels,
            'days' => $days,
            'iso' => $iso,
            'total' => (int) $total,
            'max' => (int) $max,
            'prev_total' => $prevTotal,
            'delta_pct' => $deltaPct,
            'month_label' => $start->format('F Y'),
            'today_index' => $todayIndex,
            'first_day' => $start->toDateString(),
            'last_day' => $end->toDateString(),
        ];
    }

    private function todayCategoryMix(Carbon $today): array
    {
        $todayStr = $today->toDateString();

        // Aggregate sold = active blocks today; total = active rooms in each category.
        $rooms = $this->scopeRooms(Room::query())
            ->with('category')
            ->where('status', RoomStatus::Active->value)
            ->get();

        if ($rooms->isEmpty()) {
            return ['rows' => []];
        }

        $sold = AvailabilityBlock::query()
            ->whereIn('room_id', $rooms->pluck('id')->all())
            ->where('type', AvailabilityBlockType::Booked->value)
            ->whereDate('date', $todayStr)
            ->pluck('room_id')
            ->countBy()
            ->all();

        $byCat = [];
        foreach ($rooms as $r) {
            $key = $r->category?->name ?? 'Uncategorised';
            $byCat[$key] ??= ['name' => $key, 'sold' => 0, 'total' => 0];
            $byCat[$key]['total']++;
            if (isset($sold[$r->id]) && $sold[$r->id] > 0) {
                $byCat[$key]['sold']++;
            }
        }

        return ['rows' => array_values($byCat)];
    }

    /**
     * Hand-picked "things the operator should act on today" - the
     * dashboard's job is to surface what to do, not just what is.
     *
     * Each entry: { label, count, href }. Empty array when nothing
     * needs attention.
     *
     * @param  Collection<int, Booking>  $outstanding
     * @param  Collection<int, Booking>  $arrivals
     * @return list<array{label: string, count: int, href: string, urgency: string}>
     */
    private function needsAttention(Carbon $today, Collection $outstanding, Collection $arrivals): array
    {
        $items = [];

        // Holds whose balance-due deadline is within 24h - auto-cancel
        // sweeps these tomorrow if the operator doesn't act.
        $expiringHolds = $this->scopeBookings(Booking::query())
            ->where('booking_status', BookingStatus::OnHold)
            ->where('balance_due', '>', 0)
            ->whereDate('balance_due_at', '<=', $today->copy()->addDay()->toDateString())
            ->count();
        if ($expiringHolds > 0) {
            // OnHold + non-zero balance is the exact shape of "deposit
            // deadline" rows. Bookings console with status=on_hold
            // shows them; the "outstanding" chip narrows further so
            // the operator sees only unpaid holds.
            $items[] = [
                'label' => 'Deposit deadlines within 24h',
                'count' => $expiringHolds,
                'href' => route('tenant.bookings.index', ['status' => 'on_hold', 'outstanding' => '1']),
                'urgency' => 'critical',
            ];
        }

        // Confirmed bookings whose check-in date has already passed
        // but the guest hasn't been marked as checked-in or no-show.
        $potentialNoShows = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', [BookingStatus::Confirmed, BookingStatus::OnHold])
            ->whereDate('checkin_date', '<', $today->toDateString())
            ->count();
        if ($potentialNoShows > 0) {
            // Bookings index runs the same query when overdue=1 is set
            // (Confirmed/OnHold + checkin_date < today), so this deep
            // link lands the operator on the exact rows the count
            // measures.
            $items[] = [
                'label' => 'Check-ins to confirm or mark no-show',
                'count' => $potentialNoShows,
                'href' => route('tenant.bookings.index', ['overdue' => '1']),
                'urgency' => 'critical',
            ];
        }

        // Arrivals expected today (already in the Today widget but
        // worth surfacing as an action when count > 0). Scroll into
        // the "Arriving today" section on the reservations page
        // rather than dumping the operator at the top and hoping
        // they scroll - see the id="arrivals-today" anchor there.
        if ($arrivals->isNotEmpty()) {
            $items[] = [
                'label' => 'Arrivals expected today',
                'count' => $arrivals->count(),
                'href' => route('tenant.reservations.index').'#arrivals-today',
                'urgency' => 'attention',
            ];
        }

        // Category-only reservations arriving today - the front desk
        // has to pick a room + take payment before they can be
        // checked in, so this is a critical action item.
        $pid = $this->propertyId;
        $unassignedTodayCount = Booking::query()
            ->whereNull('room_id')
            ->whereNotNull('room_category_id')
            ->whereIn('booking_status', [
                BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold,
            ])
            ->whereDate('checkin_date', $today->toDateString())
            ->when($pid, fn ($q, $pid) => $q->whereHas(
                'roomCategory', fn ($c) => $c->where('property_id', $pid),
            ))
            ->count();
        if ($unassignedTodayCount > 0) {
            $items[] = [
                'label' => 'Unassigned reservations arriving today',
                'count' => $unassignedTodayCount,
                'href' => route('tenant.reservations.index').'#unassigned',
                'urgency' => 'critical',
            ];
        }

        // Outstanding balance - deep-link into the in-house page which
        // opens the "outstanding" bucket panel showing the guest list
        // with balance breakdown. Single-row case still deep-links to
        // that reservation for a one-click resolution.
        if ($outstanding->isNotEmpty()) {
            $href = $outstanding->count() === 1
                ? route('tenant.reservations.show', $outstanding->first()->booking_ref)
                : route('tenant.in-house.index', ['openBucket' => 'outstanding']);
            $items[] = [
                'label' => 'Bookings with outstanding balance',
                'count' => $outstanding->count(),
                'href' => $href,
                'urgency' => 'attention',
            ];
        }

        return $items;
    }

    /**
     * Common helper - given a current + prior value, returns a trend
     * payload usable by the view.
     *
     * @return array{value: int, delta_pct: float, direction: string, label: string}
     */
    private function trend(int $now, int $prev, string $label): array
    {
        if ($prev === 0) {
            return [
                'value' => $now,
                'delta_pct' => 0.0,
                'direction' => $now > 0 ? 'flat' : 'flat',
                'label' => $label,
            ];
        }
        $delta = round((($now - $prev) / $prev) * 100, 1);

        return [
            'value' => $now,
            'delta_pct' => abs($delta),
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            'label' => $label,
        ];
    }

    /**
     * Sum of projected overstay across every CheckedIn booking whose
     * booked checkout_date has passed and whose Overstay charge hasn't
     * been formalised yet. Uses each booking's own
     * {@see Booking::projectedOverstayAmount()} so we don't drift from
     * the per-booking preview shown on the Reservations show page.
     */
    private function projectedOverstayTotal(Carbon $today): int
    {
        $overstayers = $this->scopeBookings(Booking::query())
            ->with('room')
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereDate('checkout_date', '<', $today->toDateString())
            ->whereNotExists(function ($sub): void {
                $sub->from('booking_charges')
                    ->whereColumn('booking_charges.booking_id', 'bookings.id')
                    ->where('type', BookingChargeType::Overstay->value);
            })
            ->get();

        return (int) $overstayers->sum(fn (Booking $b) => $b->projectedOverstayAmount($today));
    }

    /**
     * Same-window occupancy from the prior month for the trend
     * comparison. Reuses the OccupancyCalculator with a back-dated
     * anchor.
     */
    private function priorOccupancyPercent(Carbon $today): int
    {
        // Approximate - OccupancyCalculator::rolling() is "next 30 days
        // from today". For trend we use the same calculation but
        // anchored 30 days back. Falls back to 0 if no calculator
        // overload exists.
        if (method_exists($this->occupancy, 'rollingFrom')) {
            return (int) ($this->occupancy->rollingFrom($today->copy()->subDays(30))['percent'] ?? 0);
        }

        return 0;
    }
}
