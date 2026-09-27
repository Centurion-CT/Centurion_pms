<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Accounting;

use App\Models\Expense;
use App\Services\Properties\PropertyContext;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for the P&L page. Streams monthly rows honouring the
 * same range + basis + branch filter set the Livewire view uses,
 * so a shared URL always produces the same file.
 */
class ProfitLossExportController
{
    public function __invoke(Request $request, FinanceSnapshot $finance): StreamedResponse
    {
        $today = Carbon::today();
        $range = (string) $request->query('range', 'month');
        $basis = (string) $request->query('basis', 'cash');
        $branch = (string) $request->query('branch', 'current');

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

        $daily = $finance->dailySeries($from, $to);
        $daySummary = $finance->dailySummary($from, $to);

        // Same expense scoping as the Livewire component.
        $pid = app(PropertyContext::class)->current()?->getKey();
        $expensesQuery = Expense::query();
        if ($branch === 'hq') $expensesQuery->whereNull('property_id');
        elseif ($branch === 'all') { /* no-op */ }
        elseif ($pid !== null) {
            $expensesQuery->where(fn ($q) => $q->where('property_id', $pid)->orWhereNull('property_id'));
        }
        $expenseRows = $expensesQuery
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->get();

        // Bucket into months.
        $months = collect();
        foreach ($daySummary as $row) {
            $key = substr($row['date'], 0, 7);
            $existing = $months->get($key, ['month' => $key, 'revenue' => 0, 'expenses' => 0]);
            $rowRevenue = $basis === 'accrual'
                ? (int) $row['roomRevenue'] + (int) $row['posRevenue']
                : (int) $row['posRevenue'];
            $existing['revenue'] += $rowRevenue;
            $months->put($key, $existing);
        }
        if ($basis === 'cash') {
            foreach ($daily as $row) {
                $key = substr($row['date'], 0, 7);
                $existing = $months->get($key, ['month' => $key, 'revenue' => 0, 'expenses' => 0]);
                $posForDay = 0;
                foreach ($daySummary as $r) {
                    if ($r['date'] === $row['date']) { $posForDay = (int) $r['posRevenue']; break; }
                }
                $existing['revenue'] += max(0, (int) $row['collected'] - $posForDay);
                $months->put($key, $existing);
            }
        }
        foreach ($expenseRows as $e) {
            $key = $e->incurred_on->format('Y-m');
            $existing = $months->get($key, ['month' => $key, 'revenue' => 0, 'expenses' => 0]);
            $existing['expenses'] += (int) $e->amount;
            $months->put($key, $existing);
        }
        $months = $months->sortBy('month')->values();

        $filename = 'profit-loss-'.$from->format('Ymd').'-to-'.$to->format('Ymd').'-'.$basis.'.csv';

        return response()->streamDownload(function () use ($months): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Month', 'Revenue', 'Expenses', 'Net', 'Margin %']);
            foreach ($months as $m) {
                $net = (int) $m['revenue'] - (int) $m['expenses'];
                $margin = (int) $m['revenue'] > 0
                    ? round(($net / (int) $m['revenue']) * 100, 1)
                    : '';
                fputcsv($out, [
                    $m['month'],
                    (int) $m['revenue'],
                    (int) $m['expenses'],
                    $net,
                    $margin,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
