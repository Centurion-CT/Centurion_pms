<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Tenant;
use App\Services\Reports\FinanceSnapshot;
use App\Services\Reports\InventoryTimeline;
use App\Services\Reports\ReportSnapshot;
use App\Services\Reports\SummaryWatchouts;
use Illuminate\Support\Carbon;

/**
 * Assembles the payload rendered by the Summary PDF report + the
 * ad-hoc "Send test now" preview. Runs inside a bound-tenant context
 * (caller is expected to have called $tenant->makeCurrent()).
 *
 * The scope is always property-wide ("All departments", no staff
 * filter) — the emailed report is meant to be a single, deterministic
 * snapshot, not a slice.
 */
class BuildSummaryReport
{
    public function __construct(
        private FinanceSnapshot $finance,
        private ReportSnapshot $reports,
        private InventoryTimeline $timeline,
        private SummaryWatchouts $watchouts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Tenant $tenant, Carbon $from, Carbon $to, string $periodLabel): array
    {
        $span = (int) max(1, $from->diffInDays($to, absolute: true) + 1);
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($span - 1);

        $finKpis = $this->finance->kpis($from, $to);
        $occupancy = $this->reports->occupancy($from, $to);
        $posSummary = $this->finance->posSummary($from, $to);

        $prevFinKpis = $this->finance->kpis($prevFrom, $prevTo);
        $prevOccupancy = $this->reports->occupancy($prevFrom, $prevTo);
        $prevPosSummary = $this->finance->posSummary($prevFrom, $prevTo);

        // Match Summary\Index@kpisFor — cash in the window (collected
        // minus refunds) so the weekly PDF's Total revenue matches
        // the Payments page one-for-one.
        $currentTotal = max(0, (int) $finKpis['collected'] - (int) $finKpis['refunded']);
        $previousTotal = max(0, (int) $prevFinKpis['collected'] - (int) $prevFinKpis['refunded']);

        $kpis = [
            ['label' => 'Total revenue', 'value' => $currentTotal, 'previous' => $previousTotal, 'money' => true],
            ['label' => 'POS revenue', 'value' => (int) $posSummary['total'], 'previous' => (int) $prevPosSummary['total'], 'money' => true],
            ['label' => 'Occupancy', 'value' => $occupancy['occupancyPercent'], 'previous' => $prevOccupancy['occupancyPercent'], 'suffix' => '%'],
            ['label' => 'Bookings', 'value' => (int) $finKpis['bookings'], 'previous' => (int) $prevFinKpis['bookings']],
        ];

        $daily = $this->finance->dailySummary($from, $to);

        $changes = $this->timeline->build(
            from: $from->copy()->startOfDay(),
            to: $to->copy()->endOfDay(),
            sourceLimit: 200,
        )->take(20)->all();

        return [
            'tenant' => $tenant,
            'periodLabel' => $periodLabel,
            'from' => $from,
            'to' => $to,
            'prevFrom' => $prevFrom,
            'prevTo' => $prevTo,
            'kpis' => $kpis,
            'frontOfficeRevenue' => (int) $finKpis['billed'],
            'posRevenue' => (int) $posSummary['total'],
            'posByOutlet' => $this->finance->posByOutlet($from, $to),
            'topSellers' => $this->finance->posTopProducts($from, $to, 10),
            'watchouts' => $this->watchouts->build($from, $to),
            'daily' => $daily,
            'changes' => $changes,
        ];
    }
}
