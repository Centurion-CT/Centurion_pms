<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Summary;

use App\Enums\BookingStatus;
use App\Enums\PosSaleStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Models\Product;
use App\Services\Reports\FinanceSnapshot;
use App\Services\Reports\InventoryTimeline;
use App\Services\Reports\ReportSnapshot;
use App\Services\Reports\SummaryWatchouts;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Property-wide summary, the "one screen" owners asked for.
 *
 * Composes {@see FinanceSnapshot}, {@see ReportSnapshot} and
 * {@see InventoryTimeline} so revenue, occupancy, top sellers and any
 * price / cost / tax / stock edits made during the window all sit on
 * one page. No new aggregation logic, this is pure composition +
 * presentation.
 *
 * Filter state lives in the URL so a manager can bookmark or share a
 * specific slice (e.g. "the bar last month"). Two filters:
 *   - period: chip preset OR custom from/to
 *   - department: 'all' | 'front_office' | 'outlet-{id}'
 *
 * KPI cards adapt to the department filter, for a specific outlet we
 * hide occupancy / booking cards because they're not meaningful there.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    #[Url(as: 'period', except: 'this_month')]
    public string $period = 'this_month';

    #[Url(as: 'from', except: '')]
    public string $fromDate = '';

    #[Url(as: 'to', except: '')]
    public string $toDate = '';

    /** 'all' | 'front_office' | 'outlet-{id}' */
    #[Url(as: 'dept', except: 'all')]
    public string $department = 'all';

    /** Empty string = all staff. Otherwise operator id as string. */
    #[Url(as: 'staff', except: '')]
    public string $operatorFilter = '';

    public function setPeriod(string $period): void
    {
        if (! in_array($period, ['this_month', 'first_15', 'last_month', 'last_30d', 'custom'], true)) {
            return;
        }
        $this->period = $period;
        // Named periods clear the custom dates so the URL stays clean.
        if ($period !== 'custom') {
            $this->fromDate = '';
            $this->toDate = '';
        }
    }

    public function setDepartment(string $dept): void
    {
        $this->department = $dept;
    }

    public function render(
        FinanceSnapshot $finance,
        ReportSnapshot $reports,
        InventoryTimeline $timeline,
        SummaryWatchouts $watchouts,
    ): View {
        [$from, $to] = $this->dateRange();
        [$prevFrom, $prevTo] = $this->priorWindow($from, $to);

        $outlets = Outlet::query()->active()->orderBy('name')->get(['id', 'name', 'type']);
        $operators = Operator::query()->orderBy('name')->get(['id', 'name']);

        $scope = $this->resolveScope($outlets);
        $operatorId = $this->operatorFilter !== '' ? (int) $this->operatorFilter : null;

        $kpis = $this->kpisFor($scope, $finance, $reports, $from, $to, $operatorId);
        $topSellers = $this->topSellersFor($scope, $finance, $from, $to, $operatorId);
        $revenueBreakdown = $this->revenueBreakdown($scope, $finance, $from, $to, $operatorId);
        $breakdownPanels = $this->buildBreakdownPanels($scope, $from, $to, $revenueBreakdown);
        $growth = $this->growthFor($scope, $finance, $reports, $from, $to, $prevFrom, $prevTo, $operatorId);
        $watchoutStats = $watchouts->build($from, $to, $scope['outletId'], $operatorId);
        $daily = $finance->dailySummary($from, $to, $operatorId, $scope['outletId']);

        $changes = $timeline->build(
            from: $from->copy()->startOfDay(),
            to: $to->copy()->endOfDay(),
            outletId: $scope['outletId'],
            operatorId: $operatorId,
            sourceLimit: 200,
        )->take(30)->all();

        return view('livewire.dashboard.summary.index', [
            'from' => $from,
            'to' => $to,
            'prevFrom' => $prevFrom,
            'prevTo' => $prevTo,
            'kpis' => $kpis,
            'outlets' => $outlets,
            'operators' => $operators,
            'scope' => $scope,
            'topSellers' => $topSellers,
            'revenueBreakdown' => $revenueBreakdown,
            'breakdownPanels' => $breakdownPanels,
            'growth' => $growth,
            'watchouts' => $watchoutStats,
            'changes' => $changes,
            'daily' => $daily,
            'periodChips' => $this->periodChips(),
        ]);
    }

    /**
     * Prior window of equal length ending the day before `from`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function priorWindow(Carbon $from, Carbon $to): array
    {
        $span = (int) max(1, $from->diffInDays($to, absolute: true) + 1);
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($span - 1);

        return [$prevFrom, $prevTo];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(): array
    {
        $today = Carbon::today();

        return match ($this->period) {
            'first_15' => [$today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addDays(14)],
            'last_month' => [$today->copy()->subMonth()->startOfMonth(), $today->copy()->subMonth()->endOfMonth()],
            'last_30d' => [$today->copy()->subDays(29), $today->copy()],
            'custom' => $this->customRange($today),
            default => [$today->copy()->startOfMonth(), $today->copy()],  // this_month
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function customRange(Carbon $today): array
    {
        try {
            $from = $this->fromDate !== '' ? Carbon::parse($this->fromDate)->startOfDay() : $today->copy()->startOfMonth();
            $to = $this->toDate !== '' ? Carbon::parse($this->toDate)->startOfDay() : $today->copy();
        } catch (Throwable) {
            return [$today->copy()->startOfMonth(), $today->copy()];
        }
        if ($to->lessThan($from)) {
            $to = $from->copy();
        }

        return [$from, $to];
    }

    /**
     * Resolve the department string into a structured scope the render
     * pass can dispatch on. `kind` is one of 'all' | 'front_office' |
     * 'outlet'. When 'outlet', `outletId` is populated and `outlet` is
     * the resolved model (or null if the id no longer exists, falls
     * back to 'all').
     *
     * @param  Collection<int, Outlet>  $outlets
     * @return array{kind: string, outletId: int|null, outlet: Outlet|null, label: string}
     */
    private function resolveScope($outlets): array
    {
        if ($this->department === 'front_office') {
            return ['kind' => 'front_office', 'outletId' => null, 'outlet' => null, 'label' => 'Front office'];
        }
        if (str_starts_with($this->department, 'outlet-')) {
            $id = (int) substr($this->department, 7);
            $outlet = $outlets->firstWhere('id', $id);
            if ($outlet instanceof Outlet) {
                return ['kind' => 'outlet', 'outletId' => $id, 'outlet' => $outlet, 'label' => $outlet->name];
            }
        }

        return ['kind' => 'all', 'outletId' => null, 'outlet' => null, 'label' => 'All departments'];
    }

    /**
     * @param  array{kind: string, outletId: int|null, outlet: Outlet|null, label: string}  $scope
     * @return array<string, mixed>
     */
    private function kpisFor(array $scope, FinanceSnapshot $finance, ReportSnapshot $reports, Carbon $from, Carbon $to, ?int $operatorId): array
    {
        // Booking + occupancy KPIs are property-wide by nature, a staff
        // filter doesn't scope them (bookings aren't currently attributed
        // to a single creator, and occupancy is a room-level metric).
        // The staff filter only affects POS-derived numbers below.
        $finKpis = $finance->kpis($from, $to);
        $occupancy = $reports->occupancy($from, $to);
        $posSummary = $finance->posSummary($from, $to, $operatorId);

        if ($scope['kind'] === 'front_office') {
            // Front office booking revenue = cash tied to bookings in
            // the window (payments with booking_id set, matches the
            // till). Was accrual (bookings.total_amount by checkin_date)
            // which disagreed with the Payments page whenever guests
            // paid across windows or bought extras - same reconciliation
            // fix the 'all' scope uses.
            $bookingCash = $this->bookingCashInWindow($from, $to, $operatorId);
            $avgPerBooking = $finKpis['bookings'] > 0
                ? (int) round($bookingCash / (int) $finKpis['bookings'])
                : 0;
            return [
                'cards' => [
                    ['label' => 'Booking revenue', 'value' => $bookingCash, 'money' => true, 'delta' => null, 'spark' => 'roomRevenue',
                     'sub' => 'cash tied to bookings · matches Payments'],
                    ['label' => 'Occupancy', 'value' => $occupancy['occupancyPercent'], 'suffix' => '%', 'delta' => null, 'spark' => null],
                    ['label' => 'Bookings', 'value' => $finKpis['bookings'], 'delta' => null, 'spark' => 'bookings'],
                    ['label' => 'Avg per booking', 'value' => $avgPerBooking, 'money' => true, 'delta' => null, 'spark' => null],
                ],
            ];
        }

        if ($scope['kind'] === 'outlet' && $scope['outletId'] !== null) {
            $row = collect($finance->posByOutlet($from, $to, $operatorId))->firstWhere('outlet_id', $scope['outletId']);
            $tops = collect($finance->posTopProducts($from, $to, 50, $operatorId))
                ->filter(fn (array $r): bool => $this->productBelongsToOutlet((int) $r['product_id'], $scope['outletId']));
            $topName = $tops->first()['product_name'] ?? '-';

            return [
                'cards' => [
                    ['label' => 'Outlet revenue', 'value' => (int) ($row['revenue'] ?? 0), 'money' => true, 'delta' => null, 'spark' => 'posRevenue'],
                    ['label' => 'Sales count', 'value' => (int) ($row['sales_count'] ?? 0), 'delta' => null, 'spark' => 'posCount'],
                    ['label' => 'Avg ticket', 'value' => (int) ($row['avg_ticket'] ?? 0), 'money' => true, 'delta' => null, 'spark' => null],
                    ['label' => 'Top seller', 'value' => 0, 'text' => (string) $topName, 'delta' => null, 'spark' => null],
                ],
            ];
        }

        // 'all' - Total revenue is cash in the window (collected minus
        // refunds), matching the Payments page's Net payout to the
        // rupee. Accrual-basis definitions drifted from the till
        // whenever guests paid across windows or bought extras that
        // the booking row didn't update - one number, one definition
        // wins. Scoped by operator when the staff filter is set so
        // the KPI moves with the picker (FinanceSnapshot::kpis is
        // property-wide by design, so we compute cash inline here).
        $totalRevenue = $this->cashInWindow($from, $to, $operatorId);

        // Expenses in-window + resulting Net (cash basis) so
        // Summary answers "did we make money this window?" in one
        // glance. Reads FinanceSnapshot::expenseTotal so the same
        // scope Accounting → Expenses uses (this branch + HQ) is
        // honoured here - no divergence between surfaces.
        $expensesInWindow = $finance->expenseTotal($from, $to);
        $netAfterExpenses = $totalRevenue - $expensesInWindow;

        return [
            'cards' => [
                ['label' => 'Total revenue', 'value' => $totalRevenue, 'money' => true, 'delta' => null, 'spark' => 'totalRevenue',
                 'sub' => 'cash in this window · matches Payments'],
                ['label' => 'Expenses', 'value' => $expensesInWindow, 'money' => true, 'delta' => null, 'spark' => null,
                 'sub' => 'logged via Accounting · this branch + HQ',
                 'link' => route('tenant.accounting.expenses.index')],
                ['label' => 'Net (cash)', 'value' => $netAfterExpenses, 'money' => true, 'delta' => null, 'spark' => null,
                 'sub' => 'revenue − expenses · drill in P&L',
                 'link' => route('tenant.accounting.profit-loss.index'),
                 'tone' => $netAfterExpenses < 0 ? 'danger' : null],
                ['label' => 'Occupancy', 'value' => $occupancy['occupancyPercent'], 'suffix' => '%', 'delta' => null, 'spark' => null],
                ['label' => 'POS sales', 'value' => (int) $posSummary['count'], 'delta' => $posSummary['delta'], 'spark' => 'posCount'],
                ['label' => 'Bookings', 'value' => (int) $finKpis['bookings'], 'delta' => null, 'spark' => 'bookings'],
            ],
        ];
    }

    /**
     * Side-by-side "this period vs previous period" numbers. Growth
     * percentages are computed from the raw values so the view can
     * colour the arrow without having to know how they were derived.
     *
     * @param  array{kind: string, outletId: int|null, outlet: Outlet|null, label: string}  $scope
     * @return array<string, array{label: string, current: int|float, previous: int|float, delta: float|null, money?: bool, suffix?: string}>
     */
    private function growthFor(
        array $scope,
        FinanceSnapshot $finance,
        ReportSnapshot $reports,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
        ?int $operatorId,
    ): array {
        $cur = $finance->kpis($from, $to);
        $prev = $finance->kpis($prevFrom, $prevTo);
        $curPos = $finance->posSummary($from, $to, $operatorId);
        $prevPos = $finance->posSummary($prevFrom, $prevTo, $operatorId);
        $curOcc = $reports->occupancy($from, $to);
        $prevOcc = $reports->occupancy($prevFrom, $prevTo);

        // Growth compare uses the same cash-basis definition as the
        // Total revenue KPI (scoped by operator when the picker is
        // set) so the two numbers stay coherent.
        $curTotal = $this->cashInWindow($from, $to, $operatorId);
        $prevTotal = $this->cashInWindow($prevFrom, $prevTo, $operatorId);

        return [
            'totalRevenue' => [
                'label' => 'Total revenue',
                'current' => $curTotal,
                'previous' => $prevTotal,
                'delta' => $this->deltaPercent($curTotal, $prevTotal),
                'money' => true,
            ],
            'posRevenue' => [
                'label' => 'POS revenue',
                'current' => (int) $curPos['total'],
                'previous' => (int) $prevPos['total'],
                'delta' => $this->deltaPercent((int) $curPos['total'], (int) $prevPos['total']),
                'money' => true,
            ],
            'occupancy' => [
                'label' => 'Occupancy',
                'current' => $curOcc['occupancyPercent'],
                'previous' => $prevOcc['occupancyPercent'],
                'delta' => $this->deltaPercent((int) round($curOcc['occupancyPercent'] * 10), (int) round($prevOcc['occupancyPercent'] * 10)),
                'suffix' => '%',
            ],
            'bookings' => [
                'label' => 'Bookings',
                'current' => (int) $cur['bookings'],
                'previous' => (int) $prev['bookings'],
                'delta' => $this->deltaPercent((int) $cur['bookings'], (int) $prev['bookings']),
            ],
        ];
    }

    private function deltaPercent(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param  array{kind: string, outletId: int|null, outlet: Outlet|null, label: string}  $scope
     * @return list<array<string, mixed>>
     */
    private function topSellersFor(array $scope, FinanceSnapshot $finance, Carbon $from, Carbon $to, ?int $operatorId): array
    {
        if ($scope['kind'] === 'front_office') {
            // Front-office "top rooms" is a different shape, return
            // empty for MVP; the ReportSnapshot::occupancy() call
            // already surfaces per-category occupancy, which the view
            // will render instead.
            return [];
        }
        if ($scope['kind'] === 'outlet' && $scope['outletId'] !== null) {
            return array_values(array_filter(
                $finance->posTopProducts($from, $to, 50, $operatorId),
                fn (array $r): bool => $this->productBelongsToOutlet((int) $r['product_id'], $scope['outletId']),
            ));
        }

        return $finance->posTopProducts($from, $to, 10, $operatorId);
    }

    /**
     * @param  array{kind: string, outletId: int|null, outlet: Outlet|null, label: string}  $scope
     * @return array<string, mixed>
     */
    private function revenueBreakdown(array $scope, FinanceSnapshot $finance, Carbon $from, Carbon $to, ?int $operatorId): array
    {
        $posByOutlet = $finance->posByOutlet($from, $to, $operatorId);
        $posSummary = $finance->posSummary($from, $to, $operatorId);

        if ($scope['kind'] === 'outlet' && $scope['outletId'] !== null) {
            $posByOutlet = array_values(array_filter(
                $posByOutlet,
                fn (array $r): bool => (int) $r['outlet_id'] === $scope['outletId'],
            ));
        }

        return [
            // Front office row uses cash-tied-to-booking so the
            // breakdown bars sum to the Total revenue KPI. Extras
            // row is dropped from the breakdown card since its
            // accrual definition would mix bases; the cash already
            // includes payments for BookingCharges via the
            // booking-scoped payment stream.
            'frontOfficeRevenue' => $this->bookingCashInWindow($from, $to, $operatorId),
            'chargesRevenue' => 0,
            'posRevenue' => (int) $posSummary['total'],
            'posByOutlet' => $posByOutlet,
        ];
    }

    /**
     * Cash-basis revenue in the window: net of succeeded payments
     * minus refunds. Reconciles with the Payments page one-for-one
     * so operators don't chase phantom gaps between the two pages.
     * Scoped by operator when the staff filter is set - matches how
     * the rest of Summary respects that picker.
     */
    private function cashInWindow(Carbon $from, Carbon $to, ?int $operatorId): int
    {
        $base = \App\Models\Payment::query()
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($operatorId !== null) {
            $base->where('collected_by_operator_id', $operatorId);
        }
        $collected = (int) (clone $base)
            ->where('status', \App\Enums\PaymentStatus::Succeeded->value)
            ->sum('amount');
        $refunded = (int) (clone $base)
            ->where('status', \App\Enums\PaymentStatus::Refunded->value)
            ->sum('amount');
        return max(0, $collected - $refunded);
    }

    /**
     * Cash-in narrowed to payments tied to a booking (booking_id
     * NOT NULL) - the front-office slice of cashInWindow. POS-only
     * payments (booking_id NULL, pos_sale_id set) are excluded so
     * the Front-office scope doesn't double-count with outlet cards.
     */
    private function bookingCashInWindow(Carbon $from, Carbon $to, ?int $operatorId): int
    {
        $base = \App\Models\Payment::query()
            ->whereNotNull('booking_id')
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($operatorId !== null) {
            $base->where('collected_by_operator_id', $operatorId);
        }
        $collected = (int) (clone $base)
            ->where('status', \App\Enums\PaymentStatus::Succeeded->value)
            ->sum('amount');
        $refunded = (int) (clone $base)
            ->where('status', \App\Enums\PaymentStatus::Refunded->value)
            ->sum('amount');
        return max(0, $collected - $refunded);
    }

    /**
     * Client-side breakdown panels for the Revenue breakdown card.
     * Each key (`sum:rooms`, `sum:extras`, `sum:outlet:{id}`)
     * corresponds to a row; the payload lists the underlying items
     * so opening is instant (no wire trip, no loader needed).
     *
     * @param  array{kind: string, outletId: int|null, outlet: Outlet|null, label: string}  $scope
     * @param  array<string, mixed>  $revenueBreakdown
     * @return array<string, array<string, mixed>>
     */
    private function buildBreakdownPanels(array $scope, Carbon $from, Carbon $to, array $revenueBreakdown): array
    {
        $panels = [];

        if (in_array($scope['kind'], ['all', 'front_office'], true)) {
            // Front office (rooms) - bookings whose checkin fell in
            // the window and count as revenue.
            $bookings = Booking::query()
                ->with(['room:id,room_number'])
                ->whereIn('booking_status', [
                    BookingStatus::Confirmed->value,
                    BookingStatus::OnHold->value,
                    BookingStatus::CheckedIn->value,
                    BookingStatus::CheckedOut->value,
                ])
                ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
                ->orderByDesc('checkin_date')
                ->limit(100)
                ->get();
            $roomsTotal = (int) $revenueBreakdown['frontOfficeRevenue'];
            if ($bookings->isNotEmpty() || $roomsTotal > 0) {
                $panels['sum:rooms'] = [
                    'id' => 'sum:rooms',
                    'title' => 'Front office (rooms)',
                    'subtitle' => 'Room revenue · '.$bookings->count().' booking'.($bookings->count() === 1 ? '' : 's'),
                    'kpis' => [
                        ['label' => 'Bookings', 'value' => (string) $bookings->count()],
                        ['label' => 'Revenue',  'value' => '₦'.number_format($roomsTotal)],
                    ],
                    'rows' => $bookings->map(fn (Booking $b) => [
                        'primary' => (string) $b->guest_name,
                        'ref' => (string) $b->booking_ref,
                        'meta' => trim(
                            ($b->room?->room_number ? 'Rm '.$b->room->room_number : '-')
                            .' · '.($b->checkin_date?->format('j M') ?? '-')
                            .' → '.($b->checkout_date?->format('j M') ?? '-'),
                        ),
                        'amount' => (int) ($b->total_amount ?? 0),
                        'url' => route('tenant.reservations.show', $b->booking_ref),
                    ])->all(),
                ];
            }

            // Front office (extras) - BookingCharges created in the
            // window (F&B, extension, laundry, overstay, etc).
            $charges = BookingCharge::query()
                ->with(['booking:id,booking_ref,guest_name'])
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
            $extrasTotal = (int) ($revenueBreakdown['chargesRevenue'] ?? 0);
            if ($extrasTotal > 0) {
                $panels['sum:extras'] = [
                    'id' => 'sum:extras',
                    'title' => 'Front office (extras)',
                    'subtitle' => 'F&B, laundry, extension, overstay · '.$charges->count().' item'.($charges->count() === 1 ? '' : 's'),
                    'kpis' => [
                        ['label' => 'Items',   'value' => (string) $charges->count()],
                        ['label' => 'Revenue', 'value' => '₦'.number_format($extrasTotal)],
                    ],
                    'rows' => $charges->map(fn (BookingCharge $c) => [
                        'primary' => (string) $c->description,
                        'ref' => (string) ($c->booking?->booking_ref ?? ''),
                        'meta' => (string) ($c->booking?->guest_name ?? '-')
                            .' · '.($c->created_at?->format('j M · H:i') ?? '-')
                            .' · '.($c->settled ? 'settled' : 'unsettled'),
                        'amount' => (int) $c->amount,
                        'url' => $c->booking?->booking_ref
                            ? route('tenant.reservations.show', $c->booking->booking_ref)
                            : null,
                    ])->all(),
                ];
            }
        }

        // POS outlets - one panel per outlet showing that outlet's
        // sales in the window.
        foreach (($revenueBreakdown['posByOutlet'] ?? []) as $o) {
            $outletId = (int) ($o['outlet_id'] ?? 0);
            if ($outletId <= 0) continue;
            $sales = PosSale::query()
                ->with(['operator:id,name'])
                ->where('outlet_id', $outletId)
                ->where('status', PosSaleStatus::Completed->value)
                ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orderByDesc('sold_at')
                ->limit(100)
                ->get();
            $panels['sum:outlet:'.$outletId] = [
                'id' => 'sum:outlet:'.$outletId,
                'title' => (string) $o['outlet_name'],
                'subtitle' => ((string) $o['outlet_type']).' · '.number_format((int) $o['sales_count']).' sale'.($o['sales_count'] === 1 ? '' : 's'),
                'kpis' => [
                    ['label' => 'Sales',   'value' => (string) (int) $o['sales_count']],
                    ['label' => 'Revenue', 'value' => '₦'.number_format((int) $o['revenue'])],
                    ['label' => 'Avg',     'value' => '₦'.number_format((int) ($o['avg_ticket'] ?? 0))],
                ],
                'rows' => $sales->map(fn (PosSale $s) => [
                    'primary' => (string) $s->reference,
                    'ref' => '',
                    'meta' => ($s->operator?->name ?? '-')
                        .' · '.($s->sold_at?->format('j M · H:i') ?? '-'),
                    'amount' => (int) $s->total,
                    'url' => null,
                ])->all(),
            ];
        }

        return $panels;
    }

    /**
     * Whether the product with the given id currently belongs to the
     * given outlet. Small in-request cache so the "top sellers" filter
     * doesn't re-query for each row.
     *
     * @var array<int, int>|null
     */
    private ?array $productOutletCache = null;

    private function productBelongsToOutlet(int $productId, ?int $outletId): bool
    {
        if ($outletId === null) {
            return false;
        }
        if ($this->productOutletCache === null) {
            $this->productOutletCache = Product::query()
                ->pluck('outlet_id', 'id')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        return ($this->productOutletCache[$productId] ?? null) === $outletId;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function periodChips(): array
    {
        return [
            ['key' => 'this_month', 'label' => 'This month'],
            ['key' => 'first_15',   'label' => 'First 15 of month'],
            ['key' => 'last_month', 'label' => 'Last month'],
            ['key' => 'last_30d',   'label' => 'Last 30 days'],
            ['key' => 'custom',     'label' => 'Custom'],
        ];
    }
}
