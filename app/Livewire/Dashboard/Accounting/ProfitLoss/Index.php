<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Accounting\ProfitLoss;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Properties\PropertyContext;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Profit & Loss - third app under Accounting. Composes revenue
 * from FinanceSnapshot with expenses from the Expenses module to
 * produce a bottom-line Net per period.
 *
 * Basis:
 *   - cash    (default): collected payments − expenses created in window
 *   - accrual: billed (booking total_amount for stays checking-in
 *              in-window) − expenses created in window
 *
 * Branch scope mirrors the Expenses module - this branch + HQ /
 * HQ only / all branches - so a chain-level insurance premium
 * lands in the report regardless of which branch is selected on
 * the sidebar.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    /** today | week | month | year | custom */
    #[Url(as: 'range', except: 'month')]
    public string $range = 'month';

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    /** cash | accrual */
    #[Url(as: 'basis', except: 'cash')]
    public string $basis = 'cash';

    /** current | hq | all - same three modes as the Expenses filter. */
    #[Url(as: 'branch', except: 'current')]
    public string $filterBranch = 'current';

    public function pickRange(string $range): void
    {
        if (! in_array($range, ['today', 'week', 'month', 'year', 'custom'], true)) return;
        $this->range = $range;
        if ($range !== 'custom') {
            $this->customFrom = '';
            $this->customTo = '';
        }
    }

    public function toggleBasis(string $b): void
    {
        if (in_array($b, ['cash', 'accrual'], true)) {
            $this->basis = $b;
        }
    }

    /**
     * @return array{from: Carbon, to: Carbon, label: string}
     */
    private function resolveRange(): array
    {
        $today = Carbon::today();
        return match ($this->range) {
            'today' => ['from' => $today, 'to' => $today, 'label' => 'Today'],
            'week'  => ['from' => $today->copy()->startOfWeek(), 'to' => $today, 'label' => 'This week'],
            'year'  => ['from' => $today->copy()->startOfYear(), 'to' => $today, 'label' => 'This year'],
            'custom' => (function () use ($today): array {
                try {
                    $from = $this->customFrom !== '' ? Carbon::parse($this->customFrom) : $today->copy()->startOfMonth();
                    $to   = $this->customTo   !== '' ? Carbon::parse($this->customTo)   : $today;
                } catch (\Throwable) {
                    $from = $today->copy()->startOfMonth();
                    $to = $today;
                }
                if ($to->lt($from)) [$from, $to] = [$to, $from];
                return ['from' => $from, 'to' => $to, 'label' => $from->format('j M').' → '.$to->format('j M Y')];
            })(),
            default => ['from' => $today->copy()->startOfMonth(), 'to' => $today, 'label' => 'This month'],
        };
    }

    /**
     * Branch-scoped expenses query - mirrors the Expenses module so
     * an operator viewing P&L for "this branch + HQ" sees the same
     * expenses total on both surfaces.
     */
    private function expenseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = Expense::query();
        $pid = app(PropertyContext::class)->current()?->getKey();
        if ($this->filterBranch === 'hq') {
            $q->whereNull('property_id');
        } elseif ($this->filterBranch === 'all') {
            // no filter
        } elseif ($pid !== null) {
            $q->where(fn ($qq) => $qq->where('property_id', $pid)->orWhereNull('property_id'));
        }
        return $q;
    }

    public function render(FinanceSnapshot $finance): View
    {
        $range = $this->resolveRange();
        $from = $range['from'];
        $to = $range['to'];

        $kpis = $finance->kpis($from, $to);
        $daily = $finance->dailySeries($from, $to);
        $daySummary = $finance->dailySummary($from, $to);

        // Revenue depends on basis. FinanceSnapshot's `billed` is
        // booking total_amount by checkin_date - the accrual basis.
        // `collected` is payments received in-window - cash basis.
        // POS revenue is always cash - POS sales are collected at
        // the till.
        $roomsRevenue = $this->basis === 'accrual'
            ? array_sum(array_column($daySummary, 'roomRevenue'))
            : max(0, (int) $kpis['collected'] - array_sum(array_column($daySummary, 'posRevenue')));
        $posRevenue = array_sum(array_column($daySummary, 'posRevenue'));
        $extrasRevenue = $this->basis === 'accrual'
            ? (int) $kpis['chargesBilled']
            : 0; // extras are captured via collected on the cash basis; not double-count.
        $refunded = (int) $kpis['refunded'];

        $grossRevenue = $roomsRevenue + $posRevenue + $extrasRevenue;
        $netRevenue = max(0, $grossRevenue - $refunded);

        // Expenses in window - sum + per-category breakdown.
        $expenseRows = $this->expenseQuery()
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->with('category:id,name,color')
            ->get();
        $expensesTotal = (int) $expenseRows->sum('amount');
        $expensesByCategory = $expenseRows
            ->groupBy(fn ($e) => $e->category?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'name'  => $group->first()->category?->name ?? 'Uncategorised',
                'color' => $group->first()->category?->color,
                'total' => (int) $group->sum('amount'),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        $net = $netRevenue - $expensesTotal;
        $margin = $netRevenue > 0 ? round(($net / $netRevenue) * 100, 1) : null;

        // Previous equal window for delta.
        $span = (int) max(1, $from->diffInDays($to, absolute: true) + 1);
        $prevFrom = $from->copy()->subDays($span);
        $prevTo = $from->copy()->subDay();
        $prevKpis = $finance->kpis($prevFrom, $prevTo);
        $prevDaySummary = $finance->dailySummary($prevFrom, $prevTo);
        $prevRoomsRevenue = $this->basis === 'accrual'
            ? array_sum(array_column($prevDaySummary, 'roomRevenue'))
            : max(0, (int) $prevKpis['collected'] - array_sum(array_column($prevDaySummary, 'posRevenue')));
        $prevPosRevenue = array_sum(array_column($prevDaySummary, 'posRevenue'));
        $prevExtras = $this->basis === 'accrual' ? (int) $prevKpis['chargesBilled'] : 0;
        $prevGross = $prevRoomsRevenue + $prevPosRevenue + $prevExtras;
        $prevNetRevenue = max(0, $prevGross - (int) $prevKpis['refunded']);
        $prevExpenses = (int) $this->expenseQuery()
            ->whereBetween('incurred_on', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->sum('amount');
        $prevNet = $prevNetRevenue - $prevExpenses;

        $netDelta = $prevNet !== 0
            ? round((($net - $prevNet) / abs($prevNet)) * 100, 1)
            : null;

        // Monthly trend for the range - bucket every day into
        // year-month and produce parallel revenue + expenses series.
        $monthly = collect();
        foreach ($daySummary as $row) {
            $key = substr($row['date'], 0, 7); // YYYY-MM
            $existing = $monthly->get($key, ['month' => $key, 'revenue' => 0, 'expenses' => 0]);
            $rowRevenue = $this->basis === 'accrual'
                ? (int) $row['roomRevenue'] + (int) $row['posRevenue']
                : (int) $row['posRevenue'];
            $existing['revenue'] += $rowRevenue;
            $monthly->put($key, $existing);
        }
        // Add per-day collected to monthly totals for cash basis.
        if ($this->basis === 'cash') {
            foreach ($daily as $row) {
                $key = substr($row['date'], 0, 7);
                $existing = $monthly->get($key, ['month' => $key, 'revenue' => 0, 'expenses' => 0]);
                // Collected already includes room + extras + POS-paid-via-booking;
                // subtract the POS row we already added to avoid double-count.
                $posForDay = 0;
                foreach ($daySummary as $r) {
                    if ($r['date'] === $row['date']) { $posForDay = (int) $r['posRevenue']; break; }
                }
                $existing['revenue'] += max(0, (int) $row['collected'] - $posForDay);
                $monthly->put($key, $existing);
            }
        }
        // Layer expenses in.
        foreach ($expenseRows as $e) {
            $key = $e->incurred_on->format('Y-m');
            $existing = $monthly->get($key, ['month' => $key, 'revenue' => 0, 'expenses' => 0]);
            $existing['expenses'] += (int) $e->amount;
            $monthly->put($key, $existing);
        }
        $monthly = $monthly
            ->map(fn ($m) => array_merge($m, ['net' => (int) $m['revenue'] - (int) $m['expenses']]))
            ->sortBy('month')
            ->values();

        // Sparkline scaling for the SVG in the view.
        $maxAbs = max(1, ...array_map(fn ($m) => max((int) $m['revenue'], (int) $m['expenses']), $monthly->all()));

        return view('livewire.dashboard.accounting.profit-loss.index', [
            'rangeMeta' => $range,
            'revenue' => [
                'rooms' => (int) $roomsRevenue,
                'pos' => (int) $posRevenue,
                'extras' => (int) $extrasRevenue,
                'refunded' => (int) $refunded,
                'gross' => (int) $grossRevenue,
                'net' => (int) $netRevenue,
            ],
            'expenses' => [
                'total' => (int) $expensesTotal,
                'by_category' => $expensesByCategory,
            ],
            'net' => (int) $net,
            'margin' => $margin,
            'netDelta' => $netDelta,
            'prevNet' => (int) $prevNet,
            'monthly' => $monthly,
            'maxAbs' => (int) $maxAbs,
        ]);
    }
}
