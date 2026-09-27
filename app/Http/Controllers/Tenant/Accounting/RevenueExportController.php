<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Accounting;

use App\Services\Reports\FinanceSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for the revenue snapshot. Streams the same daily
 * series the on-screen chart uses so a shared URL produces the
 * exact rows the operator sees, branch-scoped via the same
 * PropertyContext FinanceSnapshot reads internally.
 */
class RevenueExportController
{
    public function __invoke(Request $request, FinanceSnapshot $finance): StreamedResponse
    {
        $today = Carbon::today();
        $range = (string) $request->query('range', 'month');

        [$from, $to] = match ($range) {
            'today' => [$today, $today],
            'week'  => [$today->copy()->startOfWeek(), $today],
            'year'  => [$today->copy()->startOfYear(), $today],
            'custom' => (function () use ($request, $today): array {
                try {
                    $f = $request->filled('from') ? Carbon::parse($request->query('from')) : $today->copy()->startOfMonth();
                    $t = $request->filled('to')   ? Carbon::parse($request->query('to'))   : $today;
                } catch (\Throwable) {
                    $f = $today->copy()->startOfMonth();
                    $t = $today;
                }
                if ($t->lt($f)) [$f, $t] = [$t, $f];
                return [$f, $t];
            })(),
            default => [$today->copy()->startOfMonth(), $today],
        };

        $rows = $finance->dailySummary($from, $to);
        $series = $finance->dailySeries($from, $to);
        // Index the collected/refunded series by date so the CSV
        // shows both accrual (billed) and cash (collected) side by side.
        $seriesByDate = [];
        foreach ($series as $s) {
            $seriesByDate[$s['date']] = $s;
        }

        $filename = 'revenue-'.$from->format('Ymd').'-to-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows, $seriesByDate): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Date',
                'Rooms revenue (billed)',
                'POS revenue',
                'Extras billed',
                'Collected',
                'Refunded',
                'Bookings',
                'POS sales',
            ]);
            foreach ($rows as $r) {
                $s = $seriesByDate[$r['date']] ?? ['billed' => 0, 'collected' => 0, 'refunded' => 0];
                fputcsv($out, [
                    $r['date'],
                    $r['roomRevenue'],
                    $r['posRevenue'],
                    max(0, (int) $s['billed'] - (int) $r['roomRevenue']),
                    $s['collected'],
                    $s['refunded'],
                    $r['bookings'],
                    $r['posCount'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
