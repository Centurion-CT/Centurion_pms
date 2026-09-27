<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Accounting\Revenue;

use App\Services\Reports\FinanceSnapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Revenue snapshot — the second app under Accounting. Sits on top
 * of the existing FinanceSnapshot service so KPI numbers stay in
 * lockstep with the /dashboard/finance surface.
 *
 * Branch-scoped: FinanceSnapshot reads PropertyContext internally,
 * so the same code produces the current-branch view on multi-branch
 * tenants and the tenant-wide view on single-branch ones without
 * this component having to think about it.
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

    public function pickRange(string $range): void
    {
        if (! in_array($range, ['today', 'week', 'month', 'year', 'custom'], true)) {
            return;
        }
        $this->range = $range;
        if ($range !== 'custom') {
            $this->customFrom = '';
            $this->customTo = '';
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
                if ($to->lt($from)) {
                    [$from, $to] = [$to, $from];
                }
                return ['from' => $from, 'to' => $to, 'label' => $from->format('j M').' → '.$to->format('j M Y')];
            })(),
            default => ['from' => $today->copy()->startOfMonth(), 'to' => $today, 'label' => 'This month'],
        };
    }

    public function render(FinanceSnapshot $finance): View
    {
        $range = $this->resolveRange();
        $from = $range['from'];
        $to = $range['to'];

        $kpis = $finance->kpis($from, $to);
        $daily = $finance->dailySeries($from, $to);
        $channel = $finance->channelPerformance($from, $to);
        $daySummary = $finance->dailySummary($from, $to);

        // Aggregate the daily summary into a rooms vs POS split for
        // the period so operators see where the money came from.
        $roomsTotal = array_sum(array_column($daySummary, 'roomRevenue'));
        $posTotal   = array_sum(array_column($daySummary, 'posRevenue'));
        $posCount   = array_sum(array_column($daySummary, 'posCount'));

        // Top 3 collection days in the window — a "biggest day" list
        // that catches the eye more than a total row does.
        $topDays = collect($daily)
            ->sortByDesc('collected')
            ->take(3)
            ->values()
            ->all();

        // Sparkline points — scaled 0..100 on the y-axis so the SVG
        // in the view doesn't have to know the max.
        $maxCollected = max(1, ...array_map(fn ($d) => $d['collected'], $daily));
        $sparkPoints = array_map(fn ($d) => [
            'date' => $d['date'],
            'collected' => (int) $d['collected'],
            'y' => 100 - (int) round(($d['collected'] / $maxCollected) * 100),
        ], $daily);

        return view('livewire.dashboard.accounting.revenue.index', [
            'rangeMeta' => $range,
            'kpis' => $kpis,
            'daily' => $daily,
            'sparkPoints' => $sparkPoints,
            'channel' => $channel,
            'source_split' => [
                'rooms' => (int) $roomsTotal,
                'pos'   => (int) $posTotal,
                'pos_count' => (int) $posCount,
            ],
            'topDays' => $topDays,
        ]);
    }
}
