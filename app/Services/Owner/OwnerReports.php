<?php

declare(strict_types=1);

namespace App\Services\Owner;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\Review;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\OccupancyCalculator;
use App\Services\Payouts\TenantPayouts;
use App\Services\Reports\FinanceSnapshot;
use App\Services\Reports\ReportSnapshot;
use App\Services\Reports\SummaryWatchouts;
use Illuminate\Support\Carbon;

/**
 * The host app's owner screens (docs/host-app/PHASE-4.md), composed
 * from the services behind the web dashboard. No money or occupancy
 * maths lives here: every figure is read from
 *
 *  - {@see FinanceSnapshot}     revenue / billed / collected / refunds /
 *                               outstanding / expenses / sources / methods
 *                               (web Finance + Summary)
 *  - {@see ReportSnapshot}      ADR, RevPAR, channels, booking behaviour,
 *                               desk activity, team (web Reports)
 *  - {@see OccupancyCalculator} occupancy on the host "occupied tonight"
 *                               rule (same numbers as host/today and
 *                               host/properties)
 *  - {@see DashboardSnapshot::owed()} owed tile (web Today / host/today)
 *  - {@see SummaryWatchouts}    watch-outs (web Summary)
 *  - {@see TenantPayouts}       Paystack settlements
 *
 * Each snapshot is pinned to the scope's branch set with
 * forProperties(), so "All branches" is one aggregate query per figure
 * (no double counting of HQ expenses or shared rows). This class only
 * picks windows, buckets dates and words things.
 */
class OwnerReports
{
    public function __construct(
        private readonly FinanceSnapshot $finance,
        private readonly ReportSnapshot $reports,
        private readonly OccupancyCalculator $occupancy,
        private readonly SummaryWatchouts $watchouts,
        private readonly TenantPayouts $payouts,
    ) {}

    /**
     * Revenue over the 7 days ending $today, cash basis (succeeded
     * minus refunded by paid_at, the web Summary "Total revenue"
     * definition), with the previous 7 days for the change.
     *
     * @param  list<int>  $propertyIds
     * @return array{revenue_week: int, revenue_week_change_pct: ?float, series_7d: list<array{date: string, amount: int}>}
     */
    public function revenueWeek(array $propertyIds, Carbon $today): array
    {
        $finance = $this->finance->forProperties($propertyIds);
        $weekFrom = $today->copy()->subDays(6);
        $rows = collect($finance->dailySeries($weekFrom, $today->copy()));
        $net = fn (array $row): int => (int) $row['collected'] - (int) $row['refunded'];

        $revenue = max(0, (int) $rows->sum($net));
        $prevTo = $weekFrom->copy()->subDay();
        $prev = max(0, (int) collect($finance->dailySeries($prevTo->copy()->subDays(6), $prevTo))->sum($net));

        return [
            'revenue_week' => $revenue,
            'revenue_week_change_pct' => self::changePct($revenue, $prev),
            'series_7d' => array_values($rows->map(fn (array $row) => [
                'date' => (string) $row['date'],
                'amount' => max(0, $net($row)),
            ])->all()),
        ];
    }

    /**
     * Approvals waiting for this operator across the branches: the same
     * number as the Inbox badge (portal holds, held money, corporate
     * requests, each behind the operator's own gates). Without an
     * operator, falls back to pending guest-portal holds only.
     *
     * @param  list<int>  $propertyIds
     */
    public function approvalsWaiting(array $propertyIds, ?\App\Models\Operator $operator = null): int
    {
        if ($operator !== null) {
            $properties = \App\Models\Property::query()->whereIn('id', $propertyIds)->get();

            return app(\App\Http\Controllers\Mobile\Host\ApprovalInbox::class)->countFor($operator, $properties);
        }

        return GuestActionApproval::query()
            ->where('status', 'pending')
            ->whereHas('room', fn ($r) => $r->whereIn('property_id', $propertyIds))
            ->count();
    }

    // ── owner/home ─────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function home(OwnerScope $scope, Tenant $tenant, ?\App\Models\Operator $operator = null): array
    {
        $ids = $scope->ids();
        $today = Carbon::today();
        $week = $this->revenueWeek($ids, $today);
        $finance = $this->finance->forProperties($ids);

        $collectedToday = (int) ($finance->dailySeries($today, $today->copy())[0]['collected'] ?? 0);
        $owed = DashboardSnapshot::owed($ids);

        $accessibleIds = array_values($scope->accessible->pluck('id')->map(fn ($id) => (int) $id)->all());
        $tonight = $this->occupancy->tonight($accessibleIds);
        $sold = 0;
        $total = 0;
        foreach ($ids as $id) {
            $sold += (int) ($tonight['by_property'][$id]['sold'] ?? 0);
            $total += (int) ($tonight['by_property'][$id]['total'] ?? 0);
        }
        $sold = min($sold, $total);
        $occupancyPct = self::pct($sold, $total);

        $adrWeek = (int) $this->reports->forProperties($ids)->occupancy($today->copy()->subDays(6), $today->copy())['adr'];
        $approvals = $this->approvalsWaiting($ids, $operator);

        $payouts = $this->payouts->forTenant($tenant);
        $nextPayout = ($payouts['configured'] ?? false) && ! isset($payouts['error']) ? ($payouts['next'] ?? null) : null;

        $branches = $scope->accessible->map(function ($p) use ($tonight, $today): array {
            $id = (int) $p->id;
            $row = $this->finance->forProperties([$id])->dailySeries($today, $today->copy())[0] ?? ['collected' => 0, 'refunded' => 0];
            $rooms = (int) ($tonight['by_property'][$id]['total'] ?? 0);
            $occupied = min($rooms, (int) ($tonight['by_property'][$id]['sold'] ?? 0));

            return [
                'id' => $id,
                'name' => (string) $p->name,
                'initials' => self::initials((string) $p->name),
                'revenue_today' => max(0, (int) $row['collected'] - (int) $row['refunded']),
                'occupancy_pct' => self::pct($occupied, $rooms),
                'is_current' => false,
            ];
        })->values()->all();
        foreach ($branches as $i => $b) {
            $branches[$i]['is_current'] = $b['id'] === (int) $scope->current->id;
        }

        // For you: yesterday's summary first, then anything to act on.
        $yesterday = $today->copy()->subDay();
        $yOcc = $this->occupancy->dailyPercent($yesterday, $yesterday->copy(), $ids);
        $yRevenue = (int) ($week['series_7d'][5]['amount'] ?? 0);
        $dayBefore = (int) ($week['series_7d'][4]['amount'] ?? 0);
        $forYou = [[
            'kind' => 'summary',
            'title' => "Yesterday's summary",
            'subtitle' => self::headline((int) $yOcc[0]['pct'], $yRevenue, self::changePct($yRevenue, $dayBefore)),
            'target' => 'summary:'.$yesterday->toDateString(),
        ]];
        if ($approvals > 0) {
            $forYou[] = [
                'kind' => 'approvals',
                'title' => self::plural($approvals, 'approval waiting', 'approvals waiting'),
                'subtitle' => 'Guest requests held for your decision',
                'target' => 'approvals',
            ];
        }
        $free = $total - $sold;
        if ($total > 0 && $free > 0) {
            $forYou[] = [
                'kind' => 'unsold',
                'title' => self::plural($free, 'room unsold tonight', 'rooms unsold tonight'),
                'subtitle' => $occupancyPct.'% full. A same-day deal could fill '.($free === 1 ? 'it.' : 'them.'),
                'target' => 'occupancy',
            ];
        }
        // Overdue guests: open the longest-overdue stay.
        $overdue = Booking::query()
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereDate('checkout_date', '<', $today->toDateString())
            ->whereHas('room', fn ($r) => $r->whereIn('property_id', $ids))
            ->orderBy('checkout_date')
            ->get(['id', 'booking_ref', 'guest_name', 'checkout_date']);
        if ($overdue->isNotEmpty()) {
            $first = $overdue->first();
            $forYou[] = [
                'kind' => 'overdue',
                'title' => self::plural($overdue->count(), 'guest past check-out', 'guests past check-out'),
                'subtitle' => $overdue->count() === 1
                    ? $first->guest_name.' was due out '.$first->checkout_date->format('j M')
                    : 'Longest: '.$first->guest_name.', due out '.$first->checkout_date->format('j M'),
                'target' => 'booking:'.$first->booking_ref,
            ];
        }
        // New reviews to answer: the app has no reviews screen yet, so
        // they open yesterday's summary (where low reviews show as a
        // watch-out) rather than a web page.
        $reviews = Review::query()
            ->whereIn('property_id', $ids)
            ->whereNull('responded_at')
            ->where('created_at', '>=', $today->copy()->subDays(6)->startOfDay())
            ->count();
        if ($reviews > 0) {
            $forYou[] = [
                'kind' => 'review',
                'title' => self::plural($reviews, 'new review to answer', 'new reviews to answer'),
                'subtitle' => 'From the last 7 days',
                'target' => 'summary:'.$yesterday->toDateString(),
            ];
        }

        return [
            'scope' => $scope->scope,
            'branches_count' => $scope->count(),
            'revenue_week' => $week['revenue_week'],
            'revenue_week_change_pct' => $week['revenue_week_change_pct'],
            'series_7d' => $week['series_7d'],
            'collected_today' => $collectedToday,
            'owed_total' => (int) $owed['total'],
            'owed_stays' => (int) $owed['stays'],
            'occupancy_tonight_pct' => $occupancyPct,
            'rooms_sold_tonight' => $sold,
            'rooms_total' => $total,
            'adr_week' => $adrWeek,
            'next_payout' => $nextPayout,
            'approvals_waiting' => $approvals,
            'branches' => $branches,
            'for_you' => $forYou,
        ];
    }

    // ── owner/financials ───────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function financials(OwnerScope $scope, string $period): array
    {
        $today = Carbon::today();
        [$from, $to, $prevFrom, $prevTo, $label] = self::periodWindow($period, $today);
        $finance = $this->finance->forProperties($scope->ids());

        $kpis = $finance->kpis($from, $to);
        $prev = $finance->kpis($prevFrom, $prevTo);
        $revenue = max(0, (int) $kpis['collected'] - (int) $kpis['refunded']);
        $prevRevenue = max(0, (int) $prev['collected'] - (int) $prev['refunded']);
        $expenses = $finance->expenseTotal($from, $to);
        $owed = $finance->owedBreakdown(5);

        return [
            'scope' => $scope->scope,
            'branches_count' => $scope->count(),
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'label' => $label,
            'revenue' => $revenue,
            'revenue_change_pct' => self::changePct($revenue, $prevRevenue),
            'comparison_label' => self::comparisonLabel($period),
            'billed' => (int) $kpis['billed'],
            'collected' => (int) $kpis['collected'],
            'outstanding' => (int) $kpis['outstanding'],
            'outstanding_stays' => $owed['stays'],
            'outstanding_companies' => $owed['companies'],
            'refunds' => (int) $kpis['refunded'],
            'refunds_count' => (int) $kpis['refundsCount'],
            'expenses' => $expenses,
            // Same as the web Finance "Net" (netProfit): cash in minus
            // refunds minus expenses logged in the window.
            'net' => (int) $kpis['collected'] - (int) $kpis['refunded'] - $expenses,
            'billed_vs_collected' => $this->billedVsCollected($finance, $period, $from, $to, $today),
            'sources' => $finance->revenueSources($from, $to),
            'methods' => $finance->collectedByMethod($from, $to),
            'owed' => $owed['rows'],
            'pdf_url' => null,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon, 4: string}
     */
    public static function periodWindow(string $period, Carbon $today): array
    {
        $today = $today->copy()->startOfDay();

        switch ($period) {
            case 'today':
                return [$today->copy(), $today->copy(), $today->copy()->subDay(), $today->copy()->subDay(), $today->format('l j F')];
            case 'week':
                $from = $today->copy()->startOfWeek(Carbon::MONDAY);
                $span = (int) $from->diffInDays($today);

                return [$from, $today->copy(), $from->copy()->subWeek(), $from->copy()->subWeek()->addDays($span),
                    'Week of '.$from->format('j F')];
            case 'year':
                $from = $today->copy()->startOfYear();
                $prevFrom = $from->copy()->subYear();
                $prevTo = $prevFrom->copy()->addDays((int) $from->diffInDays($today));

                return [$from, $today->copy(), $prevFrom, $prevTo->min($prevFrom->copy()->endOfYear()->startOfDay()), $today->format('Y')];
            default: // month
                $from = $today->copy()->startOfMonth();
                $prevFrom = $from->copy()->subMonthNoOverflow();
                $prevTo = $prevFrom->copy()->addDays((int) $from->diffInDays($today))
                    ->min($prevFrom->copy()->endOfMonth()->startOfDay());

                return [$from, $today->copy(), $prevFrom, $prevTo, $today->format('F Y')];
        }
    }

    /** What revenue_change_pct compares against (same span, see periodWindow). */
    public static function comparisonLabel(string $period): string
    {
        return match ($period) {
            'today' => 'vs yesterday',
            'week' => 'vs last week at this point',
            'year' => 'vs last year at this point',
            default => 'vs last month at this point',
        };
    }

    /**
     * Billed vs collected buckets from {@see FinanceSnapshot::dailySeries()}:
     * today = the last 7 days, week = Mon..Sun, month = 7-day blocks
     * from the 1st (Wk 1..), year = Jan..Dec. Days after today are 0.
     *
     * @return list<array{label: string, billed: int, collected: int}>
     */
    private function billedVsCollected(FinanceSnapshot $finance, string $period, Carbon $from, Carbon $to, Carbon $today): array
    {
        [$start, $end] = match ($period) {
            'today' => [$today->copy()->subDays(6), $today->copy()],
            'week' => [$from->copy(), $from->copy()->addDays(6)],
            'year' => [$from->copy(), $from->copy()->endOfYear()->startOfDay()],
            default => [$from->copy(), $from->copy()->endOfMonth()->startOfDay()],
        };
        $daily = $finance->dailySeries($start, $end->copy()->min($today));

        $buckets = [];
        $key = fn (Carbon $d): string => match ($period) {
            'year' => $d->format('M'),
            'today', 'week' => $d->format('D'),
            default => 'Wk '.(intdiv($d->day - 1, 7) + 1),
        };
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $buckets[$key($d)] ??= ['label' => $key($d), 'billed' => 0, 'collected' => 0];
        }
        foreach ($daily as $row) {
            $k = $key(Carbon::parse($row['date']));
            if (isset($buckets[$k])) {
                $buckets[$k]['billed'] += (int) $row['billed'];
                $buckets[$k]['collected'] += (int) $row['collected'];
            }
        }

        return array_values($buckets);
    }

    // ── owner/analytics ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function analytics(OwnerScope $scope, int $days): array
    {
        $ids = $scope->ids();
        $today = Carbon::today();
        $from = $today->copy()->subDays($days - 1);
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);
        $reports = $this->reports->forProperties($ids);

        $occ = $this->occupancy->window($from, $today, $ids);
        $prevOcc = $this->occupancy->window($prevFrom, $prevTo, $ids);
        $rates = $reports->occupancy($from, $today->copy());
        $prevRates = $reports->occupancy($prevFrom, $prevTo);
        $behaviour = $reports->bookingBehaviour($from, $today->copy());
        $prevBehaviour = $reports->bookingBehaviour($prevFrom, $prevTo);
        $revenue = $reports->revenue($from, $today->copy());

        $series = $this->occupancy->dailyPercent($from, $today, $ids);
        $lastYear = $this->occupancy->dailyPercent($from->copy()->subYear(), $today->copy()->subYear(), $ids);
        $lastYearHasData = collect($lastYear)->sum('sold') > 0;

        $channelTotal = (int) collect($revenue['bySource'])->sum('bookings');
        $channels = collect($revenue['bySource'])->map(fn (array $c) => [
            'key' => $c['source'],
            'label' => $c['label'],
            'pct' => $channelTotal > 0 ? round($c['bookings'] / $channelTotal * 100, 1) : 0.0,
            'bookings' => (int) $c['bookings'],
        ])->sortByDesc('bookings')->values()->all();

        $catRevenue = collect($revenue['byCategory'])->keyBy('id');
        $roomTypes = RoomCategory::query()
            ->whereIn('property_id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (RoomCategory $c) => isset($occ['by_category'][(int) $c->id]) || $catRevenue->has($c->id))
            ->map(function (RoomCategory $c) use ($catRevenue, $occ): array {
                $rev = $catRevenue->get($c->id);
                $nights = (int) ($rev['nights'] ?? 0);

                return [
                    'name' => (string) $c->name,
                    'adr' => $nights > 0 ? (int) round(((int) $rev['revenue']) / $nights) : 0,
                    'nights_sold' => $nights,
                    'occupancy_pct' => (float) ($occ['by_category'][(int) $c->id]['pct'] ?? 0.0),
                ];
            })
            ->sortByDesc('occupancy_pct')
            ->values()
            ->all();

        return [
            'scope' => $scope->scope,
            'branches_count' => $scope->count(),
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'occupancy_pct' => $occ['pct'],
            'occupancy_change_pts' => round($occ['pct'] - $prevOcc['pct'], 1),
            'adr' => (int) $rates['adr'],
            'adr_change' => (int) $rates['adr'] - (int) $prevRates['adr'],
            'revpar' => (int) $rates['revpar'],
            'revpar_change_pct' => self::changePct((int) $rates['revpar'], (int) $prevRates['revpar']),
            'occupancy_series' => array_map(fn (array $r) => ['date' => $r['date'], 'pct' => $r['pct']], $series),
            'occupancy_last_year' => $lastYearHasData
                ? array_map(fn (array $r) => ['date' => $r['date'], 'pct' => $r['pct']], $lastYear)
                : null,
            'channels' => $channels,
            'room_types' => $roomTypes,
            'avg_stay_nights' => $behaviour['avg_stay_nights'],
            'avg_stay_change' => $behaviour['avg_stay_nights'] !== null && $prevBehaviour['avg_stay_nights'] !== null
                ? round($behaviour['avg_stay_nights'] - $prevBehaviour['avg_stay_nights'], 1)
                : null,
            'lead_time_days' => $behaviour['lead_time_days'],
            'returning_guests_pct' => $behaviour['returning_guests_pct'],
            'cancellation_pct' => $behaviour['cancellation_pct'],
            'cancellations' => $behaviour['cancellations'],
            'bookings' => $behaviour['bookings'],
        ];
    }

    // ── owner/summary ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function summary(OwnerScope $scope, Carbon $date): array
    {
        $ids = $scope->ids();
        $date = $date->copy()->startOfDay();
        $prev = $date->copy()->subDay();
        $finance = $this->finance->forProperties($ids);
        $reports = $this->reports->forProperties($ids);

        $occ = $this->occupancy->dailyPercent($prev, $date, $ids);
        $occPct = (int) $occ[1]['pct'];
        $kpis = $finance->kpis($date, $date->copy());
        $prevKpis = $finance->kpis($prev, $prev->copy());
        $revenue = max(0, (int) $kpis['collected'] - (int) $kpis['refunded']);
        $prevRevenue = max(0, (int) $prevKpis['collected'] - (int) $prevKpis['refunded']);
        $revenueChange = self::changePct($revenue, $prevRevenue);
        $adr = (int) $reports->occupancy($date, $date->copy())['adr'];
        $prevAdr = (int) $reports->occupancy($prev, $prev->copy())['adr'];

        $guests = $reports->dayActivity($date, $date->copy());
        $owed = DashboardSnapshot::owed($ids);
        $money = [
            'collected' => (int) $kpis['collected'],
            'pos' => (int) $finance->posSummary($date, $date->copy())['total'],
            'refunds' => (int) $kpis['refunded'],
            'expenses' => $finance->expenseTotal($date, $date->copy()),
            'owed' => (int) $owed['total'],
        ];

        $watchouts = array_map(fn (array $w) => [
            'kind' => $w['kind'],
            'title' => $w['title'],
            'subtitle' => $w['subtitle'],
        ], $this->watchouts->items($date, $date->copy(), $ids));

        $team = array_map(fn (array $t) => [
            'name' => $t['name'],
            'role' => $t['role_label'],
            'stat' => self::teamStat($t),
        ], $reports->teamActivity($date, $date->copy(), 5));

        $headline = self::headline($occPct, $revenue, $revenueChange, $occPct - (int) $occ[0]['pct']);

        $data = [
            'scope' => $scope->scope,
            'branches_count' => $scope->count(),
            'date' => $date->toDateString(),
            'label' => $date->format('l j M'),
            'headline' => $headline,
            'occupancy_pct' => $occPct,
            'occupancy_change_pts' => $occPct - (int) $occ[0]['pct'],
            'revenue' => $revenue,
            'revenue_change_pct' => $revenueChange,
            'adr' => $adr,
            'adr_change' => $adr - $prevAdr,
            'guests' => $guests,
            'money' => $money,
            'watchouts' => $watchouts,
            'team' => $team,
        ];
        $data['share_text'] = self::shareText($scope->label(), $data);

        return $data;
    }

    /**
     * Plain-text WhatsApp summary. No em-dashes, ₦ with separators.
     *
     * @param  array<string, mixed>  $d
     */
    public static function shareText(string $place, array $d): string
    {
        $naira = fn (int $n): string => '₦'.number_format($n);
        $signed = fn (int|float $n, string $suffix): string => ($n > 0 ? '+' : ($n < 0 ? '-' : '+')).abs($n).$suffix;
        $date = Carbon::parse($d['date']);

        $occ = 'Occupancy: '.$d['occupancy_pct'].'% ('.$signed($d['occupancy_change_pts'], ' pts').')';
        $rev = 'Revenue: '.$naira((int) $d['revenue'])
            .($d['revenue_change_pct'] !== null ? ' ('.$signed($d['revenue_change_pct'], '%').')' : '');

        $lines = [
            $place.', '.$date->format('l j M Y'),
            $d['headline'],
            '',
            $occ,
            $rev,
            'Guests: '.$d['guests']['checked_in'].' checked in, '.$d['guests']['checked_out'].' checked out',
            'Collected: '.$naira((int) $d['money']['collected']),
            'Owed: '.$naira((int) $d['money']['owed']),
        ];
        if ($d['watchouts'] !== []) {
            $lines[] = '';
            $lines[] = 'Watch-outs:';
            foreach (array_slice($d['watchouts'], 0, 3) as $w) {
                $lines[] = '- '.$w['title'].' ('.$w['subtitle'].')';
            }
        }
        $lines[] = '';
        $lines[] = 'Sent from Keverna';

        return str_replace(['—', '–'], ['-', '-'], implode("\n", $lines));
    }

    /**
     * "A good day: 69% full and ₦1.18M in."
     */
    public static function headline(int $occupancyPct, int $revenue, ?float $revenueChangePct = null, ?int $occupancyChangePts = null): string
    {
        $mood = match (true) {
            $occupancyPct >= 70, ($revenueChangePct ?? 0) >= 10 && $revenue > 0 => 'A good day',
            $occupancyPct < 40 && ($revenueChangePct === null || $revenueChangePct < 0 || $revenue === 0) => 'A quiet day',
            default => 'A steady day',
        };

        return $mood.': '.$occupancyPct.'% full and '.self::shortNaira($revenue).' in.';
    }

    /** ₦1.18M, ₦46.9k, ₦800. */
    public static function shortNaira(int $amount): string
    {
        $trim = fn (float $n, int $dp): string => rtrim(rtrim(number_format($n, $dp, '.', ''), '0'), '.');

        return match (true) {
            abs($amount) >= 1_000_000 => '₦'.$trim($amount / 1_000_000, 2).'M',
            abs($amount) >= 1_000 => '₦'.$trim($amount / 1_000, 1).'k',
            default => '₦'.$amount,
        };
    }

    /**
     * @param  array{check_ins: int, check_outs: int, collected: int}  $t
     */
    private static function teamStat(array $t): string
    {
        $parts = [];
        if ($t['check_ins'] > 0) {
            $parts[] = self::plural($t['check_ins'], 'check-in', 'check-ins');
        }
        if ($t['check_outs'] > 0) {
            $parts[] = self::plural($t['check_outs'], 'check-out', 'check-outs');
        }
        if ($t['collected'] > 0) {
            $parts[] = '₦'.number_format($t['collected']).' taken';
        }

        return implode(', ', $parts);
    }

    // ── owner/occupancy ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function occupancyMonth(OwnerScope $scope, Carbon $month): array
    {
        $ids = $scope->ids();
        $today = Carbon::today();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();

        $tonight = $this->occupancy->tonight($ids);
        $days = array_map(fn (array $r) => [
            'date' => $r['date'],
            'pct' => $r['pct'],
            'is_today' => $r['date'] === $today->toDateString(),
            'is_future' => $r['date'] > $today->toDateString(),
        ], $this->occupancy->dailyPercent($start, $end, $ids));
        $forecast = array_map(fn (array $r) => ['date' => $r['date'], 'pct' => $r['pct']],
            $this->occupancy->dailyPercent($today->copy()->addDay(), $today->copy()->addDays(14), $ids));

        $names = RoomCategory::query()->whereIn('property_id', $ids)->pluck('name', 'id');
        $roomTypes = collect($tonight['by_category'])
            ->map(fn (array $r, int $cid) => [
                'name' => (string) ($names[$cid] ?? 'Uncategorised'),
                'sold' => min($r['sold'], $r['total']),
                'total' => $r['total'],
                'pct' => self::pct(min($r['sold'], $r['total']), $r['total']),
            ])
            ->sortBy([
                fn (array $a, array $b) => $b['pct'] <=> $a['pct'],
                fn (array $a, array $b) => strcmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();

        $sold = min($tonight['sold'], $tonight['total']);

        return [
            'scope' => $scope->scope,
            'branches_count' => $scope->count(),
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'tonight' => [
                'pct' => self::pct($sold, $tonight['total']),
                'sold' => $sold,
                'total' => $tonight['total'],
                'free' => $tonight['total'] - $sold,
            ],
            'days' => $days,
            'forecast_14' => $forecast,
            'room_types_tonight' => $roomTypes,
        ];
    }

    // ── helpers ────────────────────────────────────────────────────

    public static function changePct(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    public static function pct(int $part, int $total): int
    {
        return $total > 0 ? (int) round($part / $total * 100) : 0;
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    private static function initials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters;
    }
}
