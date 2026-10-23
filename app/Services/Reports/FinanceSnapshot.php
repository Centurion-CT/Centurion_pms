<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\BookingChargeType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\OutletType;
use App\Enums\PaymentStatus;
use App\Enums\PosSaleStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\CustomerWallet;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\Expense;
use App\Models\PosSaleLine;
use Illuminate\Support\Carbon;

/**
 * Analytics engine for /dashboard/finance.
 *
 * Where this differs from {@see ReportSnapshot}
 * ---------------------------------------------
 * ReportSnapshot answers operator-facing questions using `room_total`
 * only (matches the way front-desk staff think about "room revenue").
 * FinanceSnapshot answers money-facing questions and therefore uses
 * `total_amount` (rooms + packages + any add-on charges captured on the
 * booking). This intentional divergence is why the two services live
 * side by side rather than one calling the other.
 *
 * "Billed" vs "Collected"
 * -----------------------
 * - Billed  = SUM(bookings.total_amount) on Confirmed/CheckedIn/CheckedOut
 *             stays whose checkin_date falls in the reporting window.
 *             (Same anchor date + status allow-list as ReportSnapshot.)
 * - Collected = SUM(payments.amount) WHERE status=Succeeded AND paid_at IN
 *             window. Includes booking-level Paystack settlements as well
 *             as manual invoice receipts (both flow through the Payment
 *             table via the guest-invoicing feature).
 * - Refunds = SUM(payments.amount) WHERE status=Refunded AND paid_at IN window.
 * - Outstanding = SUM(bookings.balance_due) + SUM(booking_charges.amount
 *             WHERE settled=false) on open (Confirmed / OnHold / CheckedIn)
 *             bookings. This is a point-in-time number, not a windowed
 *             sum - the range picker does not affect it. Charges are in
 *             the sum so mid-stay extras (food, minibar, damage) show up
 *             as soon as the front desk adds them, matching what the
 *             reservation detail page displays.
 */
class FinanceSnapshot
{
    /**
     * Lazily-cached current branch id(s) per request cycle. Read via
     * propertyIds() from every scope helper below so multi-property
     * tenants meaningfully filter every finance surface by the
     * sidebar's picked branch. {@see forProperties()} pins an explicit
     * set instead (the host app's "All branches" owner scope), so one
     * instance aggregates several branches without double counting.
     *
     * @var list<int>|null
     */
    private ?array $cachedPropertyIds = null;
    private bool $cachedPropertyIdResolved = false;

    /**
     * A copy of this snapshot scoped to an explicit set of branches
     * (ids of the current tenant) instead of the resolved current one.
     *
     * @param  list<int>  $propertyIds
     */
    public function forProperties(array $propertyIds): static
    {
        $clone = clone $this;
        $clone->cachedPropertyIds = array_values(array_map('intval', $propertyIds));
        $clone->cachedPropertyIdResolved = true;

        return $clone;
    }

    /**
     * @return list<int>|null null = no branch filter (whole tenant)
     */
    private function propertyIds(): ?array
    {
        if (! $this->cachedPropertyIdResolved) {
            $id = app(\App\Services\Properties\PropertyContext::class)->current()?->getKey();
            $this->cachedPropertyIds = $id === null ? null : [(int) $id];
            $this->cachedPropertyIdResolved = true;
        }
        return $this->cachedPropertyIds;
    }

    /**
     * Apply a branch filter to a Booking-anchored query (via room).
     * No-op when no property is resolved (test / pre-multi contexts).
     */
    private function scopeBookings(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pids = $this->propertyIds();
        return $pids === null ? $q : $q->whereHas('room', fn ($r) => $r->whereIn('property_id', $pids));
    }

    /**
     * BookingCharge always hangs off a booking → room → property, so
     * the whereHas('booking.room', ...) filter is correct for it.
     * Payment is trickier: a POS sale settled with tender=cash/card
     * creates a Payment with pos_sale_id + a NULL booking_id (the sale
     * itself might not touch a booking at all - walk-in customer).
     * Filter Payments by "either booking.room in property OR
     * posSale.outlet in property" so POS revenue isn't accidentally
     * hidden from the per-branch KPIs.
     */
    private function scopeByBooking(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pids = $this->propertyIds();
        if ($pids === null) return $q;

        $model = $q->getModel();
        if ($model instanceof \App\Models\Payment) {
            return $q->where(function ($outer) use ($pids): void {
                $outer->whereHas('booking.room', fn ($r) => $r->whereIn('property_id', $pids))
                    ->orWhereHas('posSale.outlet', fn ($o) => $o->whereIn('property_id', $pids));
            });
        }
        return $q->whereHas('booking.room', fn ($r) => $r->whereIn('property_id', $pids));
    }

    /**
     * PosSale reaches property via its own `outlet` BelongsTo;
     * PosSaleLine reaches it one hop further via `sale.outlet`. We
     * used to call whereHas('outlet', ...) unconditionally, which
     * threw "Relation [outlet] does not exist" as soon as
     * posTopProducts() fed a PosSaleLine query in - Summary +
     * Finance both 500'd on any tenant that had POS activity.
     */
    private function scopeByOutlet(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pids = $this->propertyIds();
        if ($pids === null) return $q;

        $model = $q->getModel();
        if ($model instanceof \App\Models\PosSaleLine) {
            return $q->whereHas('sale.outlet', fn ($o) => $o->whereIn('property_id', $pids));
        }
        return $q->whereHas('outlet', fn ($o) => $o->whereIn('property_id', $pids));
    }

    /**
     * @return array<string, mixed>
     */
    public function kpis(Carbon $from, Carbon $to, int $forecastHorizon = 30): array
    {
        $revenueStatuses = $this->revenueStatuses();

        $billed = (int) $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total_amount');

        $bookings = (int) $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $collected = (int) $this->scopeByBooking(Payment::query())
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('amount');

        $refunded = (int) $this->scopeByBooking(Payment::query())
            ->where('status', PaymentStatus::Refunded->value)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('amount');
        $refundsCount = (int) $this->scopeByBooking(Payment::query())
            ->where('status', PaymentStatus::Refunded->value)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();

        // Outstanding = balance_due on open bookings + every unsettled
        // BookingCharge on those bookings + projected overstay for
        // CheckedIn guests past their booked checkout without an
        // Overstay charge yet. Missing either the charges or the
        // projection makes the KPI drift the moment the front desk
        // adds a mid-stay extra or a guest walks past checkout without
        // being formally checked out - the reservation detail page
        // showed the money owed but this card said ₦0.
        $openStatuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::OnHold->value,
            BookingStatus::CheckedIn->value,
        ];
        $outstandingBalance = (int) $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $openStatuses)
            ->sum('balance_due');
        $outstandingCharges = (int) $this->scopeByBooking(BookingCharge::query())
            ->join('bookings', 'bookings.id', '=', 'booking_charges.booking_id')
            ->whereIn('bookings.booking_status', $openStatuses)
            ->where('booking_charges.settled', false)
            ->sum('booking_charges.amount');

        $today = Carbon::today();
        $overstayers = $this->scopeBookings(Booking::query())
            ->with('room')
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereDate('checkout_date', '<', $today->toDateString())
            // No "already has an overstay charge" filter: projectedOverstayAmount()
            // only counts nights NOT yet billed, so a guest billed for 17 nights
            // and still overstaying keeps accruing (it used to drop to ₦0 here
            // while the reservation page and Today showed the debt).
            ->get();
        $projectedOverstay = (int) $overstayers->sum(fn (Booking $b) => $b->projectedOverstayAmount($today));

        $outstanding = $outstandingBalance + $outstandingCharges + $projectedOverstay;

        $net = $billed - $refunded;
        $avgBookingValue = $bookings > 0 ? (int) round($billed / $bookings) : 0;

        // Prev-window delta so the KPIs can show trend arrows.
        $span = (int) max(1, $from->diffInDays($to, absolute: true) + 1);
        $prevFrom = $from->copy()->subDays($span);
        $prevTo = $from->copy()->subDay();

        $prevBilled = (int) $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->sum('total_amount');

        $prevCollected = (int) $this->scopeByBooking(Payment::query())
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('paid_at', [$prevFrom->copy()->startOfDay(), $prevTo->copy()->endOfDay()])
            ->sum('amount');

        // Forward-looking preview so managers see a headline forecast
        // in the KPI strip without scrolling to the chart.
        $forecast = app(RevenueForecast::class)->project($forecastHorizon);
        $forecastTotal = array_sum(array_map(fn ($row) => $row['on_books'] + $row['projected'], $forecast));
        $forecastOnBooks = array_sum(array_column($forecast, 'on_books'));

        // Deposits on file - sum of every guest wallet balance for the
        // tenant. Point-in-time snapshot (no date window) since it
        // represents a running liability, not a windowed revenue metric.
        $depositsOnFile = (int) CustomerWallet::query()->sum('balance_naira');
        $fundedWallets = (int) CustomerWallet::query()->where('balance_naira', '>', 0)->count();

        // Extras revenue - F&B, extension, laundry, damage, and any
        // other booking-charge added in the window. Was missing from
        // the "billed" concept, which made Summary's Total revenue
        // look ₦hundreds-of-k lighter than the till while guests were
        // paying for extras: `bookings.total_amount` doesn't update
        // when a BookingCharge is added, so charged-to-room extras
        // weren't visible on any revenue KPI. Scoped by created_at
        // (accrual: when it was billed) so it lines up with the
        // window basis of `billed` above.
        $chargesBilled = (int) $this->scopeByBooking(BookingCharge::query())
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('amount');
        $prevChargesBilled = (int) $this->scopeByBooking(BookingCharge::query())
            ->whereBetween('created_at', [$prevFrom->copy()->startOfDay(), $prevTo->copy()->endOfDay()])
            ->sum('amount');

        // Pending refunds - cash still held on Cancelled / NoShow
        // bookings that never got refunded. Point-in-time snapshot
        // (no window) since it's a running liability, not a windowed
        // metric. The full list of these bookings lives on the
        // Refunds page.
        $pendingRefunds = 0;
        $pendingRefundsCount = 0;
        $unresolvedIds = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->pluck('id');
        if ($unresolvedIds->isNotEmpty()) {
            // Same held figure as the Refunds page: paid − refunded −
            // kept-as-forfeit. Booking ids are already branch-scoped.
            foreach (app(\App\Services\Bookings\HeldMoney::class)->byBooking($unresolvedIds) as $row) {
                if ($row['held'] > 0) {
                    $pendingRefunds += $row['held'];
                    $pendingRefundsCount++;
                }
            }
        }

        return [
            'billed' => $billed,
            'collected' => $collected,
            'refunded' => $refunded,
            'refundsCount' => $refundsCount,
            'outstanding' => $outstanding,
            'net' => $net,
            'bookings' => $bookings,
            'avgBookingValue' => $avgBookingValue,
            'billedDelta' => $this->deltaPercent($billed, $prevBilled),
            'collectedDelta' => $this->deltaPercent($collected, $prevCollected),
            'prevBilled' => $prevBilled,
            'prevCollected' => $prevCollected,
            'forecastHorizon' => $forecastHorizon,
            'forecastTotal' => (int) $forecastTotal,
            'forecastOnBooks' => (int) $forecastOnBooks,
            'depositsOnFile' => $depositsOnFile,
            'fundedWallets' => $fundedWallets,
            'chargesBilled' => $chargesBilled,
            'prevChargesBilled' => $prevChargesBilled,
            'pendingRefunds' => $pendingRefunds,
            'pendingRefundsCount' => $pendingRefundsCount,
        ];
    }

    /**
     * Daily billed vs collected vs refunded series for the trend chart.
     * Buckets sparse rows onto a dense date range so the SVG chart can
     * plot 0-value days without gaps.
     *
     * @return list<array{date: string, billed: int, collected: int, refunded: int}>
     */
    public function dailySeries(Carbon $from, Carbon $to): array
    {
        $revenueStatuses = $this->revenueStatuses();

        $billedRows = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('DATE(checkin_date) as d, SUM(total_amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd')
            ->all();

        $collectedRows = $this->scopeByBooking(Payment::query())
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->toBase()
            ->selectRaw('DATE(paid_at) as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd')
            ->all();

        $refundedRows = $this->scopeByBooking(Payment::query())
            ->where('status', PaymentStatus::Refunded->value)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->toBase()
            ->selectRaw('DATE(paid_at) as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd')
            ->all();

        $out = [];
        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $key = $cursor->toDateString();
            $out[] = [
                'date' => $key,
                'billed' => (int) ($billedRows[$key] ?? 0),
                'collected' => (int) ($collectedRows[$key] ?? 0),
                'refunded' => (int) ($refundedRows[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Compact per-day timeseries for the Summary page - one row per day
     * in the [$from, $to] window (dense; 0 for empty days) with:
     *   - roomRevenue: SUM(bookings.total_amount) by checkin_date
     *   - posRevenue:  SUM(pos_sales.total) by DATE(sold_at)
     *   - posCount:    COUNT(pos_sales.id) by DATE(sold_at)
     *   - bookings:    COUNT(bookings.id) by checkin_date
     *
     * An optional $operatorId narrows POS numbers only - booking
     * counts + revenue stay property-wide because bookings aren't
     * attributed to a single creator in the current schema.
     *
     * @return list<array{date: string, roomRevenue: int, posRevenue: int, posCount: int, bookings: int}>
     */
    public function dailySummary(Carbon $from, Carbon $to, ?int $operatorId = null, ?int $outletId = null): array
    {
        $revenueStatuses = $this->revenueStatuses();

        // When an outlet filter is active the "room revenue" and
        // "bookings" columns aren't meaningful for that scope - they're
        // property-wide numbers. Zero them out rather than double-showing.
        $roomRows = collect();
        if ($outletId === null) {
            // Datetime bounds rather than DATE strings - SQLite stores
            // the `date` cast column with a time component, so a
            // date-only upper bound would exclude `<date> 00:00:00`.
            $roomRows = $this->scopeBookings(Booking::query())
                ->whereIn('booking_status', $revenueStatuses)
                ->whereBetween('checkin_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->toBase()
                ->selectRaw('DATE(checkin_date) as d, SUM(total_amount) as total, COUNT(id) as cnt')
                ->groupBy('d')
                ->get()
                ->keyBy('d');
        }

        $posQuery = $this->scopeByOutlet(PosSale::query())
            ->completed()
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($operatorId !== null) {
            $posQuery->where('operator_id', $operatorId);
        }
        if ($outletId !== null) {
            $posQuery->where('outlet_id', $outletId);
        }
        $posRows = $posQuery
            ->toBase()
            ->selectRaw('DATE(sold_at) as d, SUM(total) as total, COUNT(id) as cnt')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $out = [];
        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $key = $cursor->toDateString();
            $rr = $roomRows->get($key);
            $pr = $posRows->get($key);
            $out[] = [
                'date' => $key,
                'roomRevenue' => (int) ($rr->total ?? 0),
                'posRevenue' => (int) ($pr->total ?? 0),
                'posCount' => (int) ($pr->cnt ?? 0),
                'bookings' => (int) ($rr->cnt ?? 0),
            ];
            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Per-channel revenue + delta vs. the previous equal window.
     *
     * @return list<array{source: string, label: string, bookings: int, revenue: int, share: float, avgValue: int, prevRevenue: int, delta: ?float}>
     */
    public function channelPerformance(Carbon $from, Carbon $to): array
    {
        $revenueStatuses = $this->revenueStatuses();

        $rows = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('source, COUNT(*) as bookings, SUM(total_amount) as revenue')
            ->groupBy('source')
            ->orderByDesc('revenue')
            ->get();

        $total = (int) $rows->sum('revenue');

        $span = (int) max(1, $from->diffInDays($to, absolute: true) + 1);
        $prevFrom = $from->copy()->subDays($span);
        $prevTo = $from->copy()->subDay();

        $prev = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->toBase()
            ->selectRaw('source, SUM(total_amount) as revenue')
            ->groupBy('source')
            ->pluck('revenue', 'source')
            ->all();

        return array_values($rows->map(function ($r) use ($total, $prev): array {
            $value = (string) $r->source;
            $enum = BookingSource::tryFrom($value);
            $revenue = (int) $r->revenue;
            $bookings = (int) $r->bookings;
            $prevRevenue = (int) ($prev[$value] ?? 0);

            return [
                'source' => $value,
                'label' => $enum?->label() ?? $value,
                'bookings' => $bookings,
                'revenue' => $revenue,
                'share' => $total > 0 ? round($revenue / $total * 100, 1) : 0.0,
                'avgValue' => $bookings > 0 ? (int) round($revenue / $bookings) : 0,
                'prevRevenue' => $prevRevenue,
                'delta' => $this->deltaPercent($revenue, $prevRevenue),
            ];
        })->all());
    }

    /**
     * Top rooms + category breakdown - mirrors the shape from
     * ReportSnapshot::revenue() so the finance page can reuse the same
     * blade markup patterns.
     *
     * @return array{topRooms: list<array<string, mixed>>, byCategory: list<array<string, mixed>>}
     */
    public function inventoryBreakdown(Carbon $from, Carbon $to): array
    {
        $revenueStatuses = $this->revenueStatuses();

        $base = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()]);

        $total = (int) (clone $base)->sum('total_amount');

        // toBase() drops to the raw query builder so the aggregate rows
        // come back as stdClass rather than Booking models - otherwise
        // static analysis complains about `$r->bookings` / `$r->revenue`
        // (they aren't real Booking properties).
        $byCategory = (clone $base)
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->leftJoin('room_categories', 'room_categories.id', '=', 'rooms.room_category_id')
            ->toBase()
            ->selectRaw('room_categories.id as cat_id, room_categories.name as cat_name, COUNT(bookings.id) as bookings, SUM(bookings.total_amount) as revenue, SUM(bookings.nights) as nights')
            ->groupBy('room_categories.id', 'room_categories.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) ($r->cat_id ?? 0),
                'name' => (string) ($r->cat_name ?? 'Uncategorised'),
                'bookings' => (int) $r->bookings,
                'revenue' => (int) $r->revenue,
                'nights' => (int) $r->nights,
                'share' => $total > 0 ? round(((int) $r->revenue) / $total * 100, 1) : 0.0,
            ])
            ->all();
        $byCategory = array_values($byCategory);

        $topRooms = (clone $base)
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->leftJoin('room_categories', 'room_categories.id', '=', 'rooms.room_category_id')
            ->toBase()
            ->selectRaw('rooms.id as room_id, rooms.room_number as room_number, room_categories.name as cat_name, COUNT(bookings.id) as bookings, SUM(bookings.total_amount) as revenue')
            ->groupBy('rooms.id', 'rooms.room_number', 'room_categories.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->room_id,
                'label' => trim((string) ($r->cat_name ?? '').' · '.($r->room_number ?? '-'), ' ·'),
                'bookings' => (int) $r->bookings,
                'revenue' => (int) $r->revenue,
            ])
            ->all();
        $topRooms = array_values($topRooms);

        return ['topRooms' => $topRooms, 'byCategory' => $byCategory];
    }

    /**
     * Most recent transactions with joined booking + channel context.
     * Kept compact - the full paginated list lives on /dashboard/payments;
     * this is the "at a glance" ledger on the finance page.
     *
     * @return list<array<string, mixed>>
     */
    public function transactionLedger(Carbon $from, Carbon $to, int $limit = 15): array
    {
        $rows = $this->scopeByBooking(Payment::query())
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with(['booking:id,booking_ref,guest_name,source'])
            ->orderByDesc('paid_at')
            ->limit($limit)
            ->get()
            ->map(function (Payment $p): array {
                $sourceValue = $p->booking?->source?->value;
                $sourceLabel = $p->booking?->source?->label();

                return [
                    'id' => $p->id,
                    'booking_ref' => $p->booking?->booking_ref,
                    'guest_name' => $p->booking?->guest_name,
                    'amount' => (int) $p->amount,
                    'status' => $p->status->value,
                    'type' => $p->type->value,
                    'paid_at' => $p->paid_at?->toIso8601String(),
                    'source' => $sourceValue,
                    'source_label' => $sourceLabel,
                    'reference' => $p->paystack_reference,
                ];
            })
            ->all();

        return array_values($rows);
    }

    /**
     * POS sales roll-up: total revenue, sales count, avg ticket size,
     * and the charge-to-room vs direct-payment split. All windowed by
     * `sold_at` (when the sale was completed) and scoped to Completed
     * status - Draft and Voided rows are excluded so the numbers
     * mirror the cash actually taken.
     *
     * @return array<string, mixed>
     */
    public function posSummary(Carbon $from, Carbon $to, ?int $operatorId = null): array
    {
        $base = $this->scopeByOutlet(PosSale::query())
            ->completed()
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($operatorId !== null) {
            $base->where('operator_id', $operatorId);
        }

        $total = (int) (clone $base)->sum('total');
        $count = (int) (clone $base)->count();
        $chargeToRoomTotal = (int) (clone $base)->whereNotNull('booking_id')->sum('total');
        $directTotal = $total - $chargeToRoomTotal;

        // Complimentary rollup. Sale total is floored to ₦0 in the comp
        // path, so we sum PosSaleLine.subtotal directly to see the
        // give-away value the operator handed out.
        $compCount = (int) (clone $base)->where('is_complimentary', true)->count();
        $compValue = (int) \App\Models\PosSaleLine::query()
            ->whereIn('pos_sale_id', (clone $base)->where('is_complimentary', true)->select('id'))
            ->sum('subtotal');

        // Prev-window delta for the trend arrow.
        $span = (int) max(1, $from->diffInDays($to, absolute: true) + 1);
        $prevFrom = $from->copy()->subDays($span);
        $prevTo = $from->copy()->subDay();
        $prevQuery = $this->scopeByOutlet(PosSale::query())
            ->completed()
            ->whereBetween('sold_at', [$prevFrom->copy()->startOfDay(), $prevTo->copy()->endOfDay()]);
        if ($operatorId !== null) {
            $prevQuery->where('operator_id', $operatorId);
        }
        $prevTotal = (int) $prevQuery->sum('total');

        return [
            'total' => $total,
            'count' => $count,
            'avgTicket' => $count > 0 ? (int) round($total / $count) : 0,
            'chargeToRoomTotal' => $chargeToRoomTotal,
            'directTotal' => $directTotal,
            'chargeToRoomShare' => $total > 0 ? round($chargeToRoomTotal / $total * 100, 1) : 0.0,
            'complimentaryCount' => $compCount,
            'complimentaryValue' => $compValue,
            'prevTotal' => $prevTotal,
            'delta' => $this->deltaPercent($total, $prevTotal),
        ];
    }

    /**
     * Per-outlet POS performance for the window: revenue, sales count,
     * average ticket, share of overall POS revenue.
     *
     * @return list<array<string, mixed>>
     */
    public function posByOutlet(Carbon $from, Carbon $to, ?int $operatorId = null): array
    {
        $query = $this->scopeByOutlet(PosSale::query())
            ->completed()
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->join('outlets', 'outlets.id', '=', 'pos_sales.outlet_id');
        if ($operatorId !== null) {
            $query->where('pos_sales.operator_id', $operatorId);
        }
        $rows = $query
            ->toBase()
            ->selectRaw('outlets.id as outlet_id, outlets.name as outlet_name, outlets.type as outlet_type, COUNT(pos_sales.id) as sales_count, SUM(pos_sales.total) as revenue')
            ->groupBy('outlets.id', 'outlets.name', 'outlets.type')
            ->orderByDesc('revenue')
            ->get();

        $grand = (int) $rows->sum('revenue');

        return array_values($rows->map(function ($r) use ($grand): array {
            $revenue = (int) $r->revenue;
            $count = (int) $r->sales_count;
            $enum = OutletType::tryFrom((string) $r->outlet_type);

            return [
                'outlet_id' => (int) $r->outlet_id,
                'outlet_name' => (string) $r->outlet_name,
                'outlet_type' => $enum?->label() ?? (string) $r->outlet_type,
                'sales_count' => $count,
                'revenue' => $revenue,
                'avg_ticket' => $count > 0 ? (int) round($revenue / $count) : 0,
                'share' => $grand > 0 ? round($revenue / $grand * 100, 1) : 0.0,
            ];
        })->all());
    }

    /**
     * Top-selling products in the window (revenue-ranked). Freeform
     * "Other" lines with no product_id are excluded - they're too
     * varied to aggregate usefully.
     *
     * @return list<array<string, mixed>>
     */
    public function posTopProducts(Carbon $from, Carbon $to, int $limit = 10, ?int $operatorId = null): array
    {
        $query = $this->scopeByOutlet(PosSaleLine::query())
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.pos_sale_id')
            ->join('outlets', 'outlets.id', '=', 'pos_sales.outlet_id')
            ->where('pos_sales.status', PosSaleStatus::Completed->value)
            ->whereBetween('pos_sales.sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotNull('pos_sale_lines.product_id');
        if ($operatorId !== null) {
            $query->where('pos_sales.operator_id', $operatorId);
        }
        $rows = $query
            ->toBase()
            ->selectRaw('pos_sale_lines.product_id as product_id, pos_sale_lines.name as product_name, outlets.name as outlet_name, SUM(pos_sale_lines.quantity) as units, SUM(pos_sale_lines.subtotal) as revenue')
            ->groupBy('pos_sale_lines.product_id', 'pos_sale_lines.name', 'outlets.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return array_values($rows->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'product_name' => (string) $r->product_name,
            'outlet_name' => (string) $r->outlet_name,
            'units' => (int) $r->units,
            'revenue' => (int) $r->revenue,
        ])->all());
    }

    /**
     * Where the window's revenue came from (accrual, the same building
     * blocks the Finance page shows, without double counting):
     *
     *  - rooms:     billed stays (bookings.total_amount by checkin_date,
     *               revenue statuses) = kpis()['billed']
     *  - pos:       completed POS sales by sold_at = posSummary()['total']
     *               (charge-to-room sales included here once; their
     *               "POS · ..." folio charges are skipped below)
     *  - extension: Extension charges added in the window
     *  - overstay:  Overstay + Late check-out charges added in the window
     *  - extras:    every other folio charge added in the window
     *               (laundry, transport, damage, packages, forfeits...)
     *
     * Every key is always present (amount 0 when nothing) so charts
     * keep a stable legend; pct is the share of the sum, 1dp.
     *
     * @return list<array{key: string, label: string, amount: int, pct: float}>
     */
    public function revenueSources(Carbon $from, Carbon $to): array
    {
        $rooms = (int) $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $this->revenueStatuses())
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total_amount');
        $pos = (int) $this->scopeByOutlet(PosSale::query())
            ->completed()
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('total');

        $charges = $this->scopeByBooking(BookingCharge::query())
            ->whereBetween('booking_charges.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(fn ($q) => $q->whereNull('booking_charges.description')
                ->orWhere('booking_charges.description', 'not like', 'POS · %'))
            ->toBase()
            ->selectRaw('booking_charges.type as type, SUM(booking_charges.amount) as total')
            ->groupBy('booking_charges.type')
            ->pluck('total', 'type')
            ->map(fn ($v) => (int) $v)
            ->all();

        $extension = (int) ($charges[BookingChargeType::Extension->value] ?? 0);
        $overstay = (int) ($charges[BookingChargeType::Overstay->value] ?? 0)
            + (int) ($charges[BookingChargeType::LateCheckout->value] ?? 0);
        $extras = (int) array_sum($charges) - $extension - $overstay;

        $rows = [
            ['key' => 'rooms', 'label' => 'Rooms', 'amount' => $rooms],
            ['key' => 'pos', 'label' => 'Restaurant and bar', 'amount' => $pos],
            ['key' => 'extension', 'label' => 'Stay extensions', 'amount' => $extension],
            ['key' => 'overstay', 'label' => 'Overstay and late check-out', 'amount' => $overstay],
            ['key' => 'extras', 'label' => 'Other extras', 'amount' => $extras],
        ];
        $total = array_sum(array_column($rows, 'amount'));

        return array_map(fn (array $r): array => $r + [
            'pct' => $total > 0 ? round($r['amount'] / $total * 100, 1) : 0.0,
        ], $rows);
    }

    /**
     * Money collected in the window by payment method (succeeded
     * payments by paid_at, same rows as kpis()['collected']). A null
     * method is an online Paystack payment (as the Finance KPI panel
     * shows it). Largest first.
     *
     * @return list<array{value: string, label: string, amount: int}>
     */
    public function collectedByMethod(Carbon $from, Carbon $to): array
    {
        $rows = $this->scopeByBooking(Payment::query())
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->toBase()
            ->selectRaw("COALESCE(method, 'paystack') as m, SUM(amount) as total")
            ->groupBy('m')
            ->pluck('total', 'm')
            ->all();

        $out = [];
        foreach ($rows as $method => $total) {
            $method = (string) $method;
            $out[] = [
                'value' => $method,
                'label' => \App\Enums\PaymentMethod::tryFrom($method)?->label() ?? ucfirst($method),
                'amount' => (int) $total,
            ];
        }
        usort($out, fn (array $a, array $b) => [$b['amount'], $a['value']] <=> [$a['amount'], $b['value']]);

        return $out;
    }

    /**
     * Who owes the money behind the Outstanding tile, using the same
     * booking set the Finance "Outstanding" KPI panel lists (open
     * Confirmed / OnHold / CheckedIn stays owing via
     * {@see Booking::outstandingTotal()}):
     *
     *  - company rows: stays billed to a corporate, one row per company
     *  - guest rows: every other stay, one row per booking
     *
     * `stays` counts guest stays owing, `companies` the companies
     * owing, `rows` the largest $limit debts.
     *
     * @return array{total: int, stays: int, companies: int, rows: list<array{kind: string, name: string, subtitle: string, amount: int, booking_ref: ?string, corporate_id: ?int}>}
     */
    public function owedBreakdown(int $limit = 5): array
    {
        $bookings = $this->scopeBookings(Booking::query())
            // Full room: outstandingTotal()'s overstay rate reads the
            // room's category, so a trimmed select would price it at 0.
            ->with(['room', 'corporate:id,name'])
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
                BookingStatus::CheckedIn->value,
            ])
            ->get()
            ->map(fn (Booking $b) => ['booking' => $b, 'owed' => (int) $b->outstandingTotal()])
            ->filter(fn (array $r) => $r['owed'] > 0);

        [$corporate, $guests] = $bookings->partition(fn (array $r) => $r['booking']->corporate_id !== null);

        $rows = $corporate->groupBy(fn (array $r) => (int) $r['booking']->corporate_id)
            ->map(function ($group, int $corporateId): array {
                $count = $group->count();

                return [
                    'kind' => 'company',
                    'name' => (string) ($group->first()['booking']->corporate?->name ?? 'Company'),
                    'subtitle' => $count.' '.($count === 1 ? 'stay' : 'stays'),
                    'amount' => (int) $group->sum('owed'),
                    'booking_ref' => null,
                    'corporate_id' => $corporateId,
                ];
            })
            ->values()
            ->concat($guests->map(function (array $r): array {
                /** @var Booking $b */
                $b = $r['booking'];
                $room = $b->room?->room_number !== null ? 'Room '.$b->room->room_number : 'No room yet';
                $when = $b->booking_status === BookingStatus::CheckedIn
                    ? 'out '.$b->checkout_date->format('j M')
                    : 'arrives '.$b->checkin_date->format('j M');

                return [
                    'kind' => 'guest',
                    'name' => (string) $b->guest_name,
                    'subtitle' => $room.', '.$when,
                    'amount' => $r['owed'],
                    'booking_ref' => (string) $b->booking_ref,
                    'corporate_id' => null,
                ];
            })->values())
            ->sortBy([
                fn (array $a, array $b) => $b['amount'] <=> $a['amount'],
                fn (array $a, array $b) => strcmp($a['name'], $b['name']),
            ])
            ->values();

        return [
            'total' => (int) $bookings->sum('owed'),
            'stays' => $guests->count(),
            'companies' => $corporate->pluck('booking.corporate_id')->unique()->count(),
            'rows' => $rows->take($limit)->values()->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function revenueStatuses(): array
    {
        return [
            BookingStatus::Confirmed->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::CheckedOut->value,
        ];
    }

    private function deltaPercent(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Sum of expenses logged in-window. Branch scoping mirrors the
     * Accounting → Expenses module: the current branch PLUS
     * property_id = null (HQ / chain-level) rows, so a group
     * insurance premium always lands in the total. Callers passing
     * a null current branch (single-branch tenants, tests) get the
     * whole-tenant sum unchanged.
     */
    public function expenseTotal(Carbon $from, Carbon $to): int
    {
        return (int) $this->scopeExpenses(Expense::query())
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');
    }

    /**
     * Per-day expense totals for the window. Dense - empty days
     * emit 0 - so the Finance daily breakdown table can add an
     * "Expenses" column without extra plumbing.
     *
     * @return list<array{date: string, expenses: int}>
     */
    public function dailyExpenseSeries(Carbon $from, Carbon $to): array
    {
        $rows = $this->scopeExpenses(Expense::query())
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('DATE(incurred_on) as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd')
            ->all();

        $out = [];
        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $key = $cursor->toDateString();
            $out[] = ['date' => $key, 'expenses' => (int) ($rows[$key] ?? 0)];
            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Branch scope for Expense queries - kept internal so the two
     * public helpers above and any future expense-based KPIs share
     * one filter definition.
     */
    private function scopeExpenses(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pids = $this->propertyIds();
        if ($pids === null) return $q;

        return $q->where(fn ($qq) => $qq->whereIn('property_id', $pids)->orWhereNull('property_id'));
    }
}
