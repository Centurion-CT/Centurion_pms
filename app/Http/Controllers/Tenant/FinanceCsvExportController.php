<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Reports\FinanceSnapshot;
use App\Services\Reports\RevenueForecast;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Exports the current Finance dashboard as a CSV — KPIs, channels,
 * daily series and forecast in one file so accountants can drop it
 * straight into Sheets / Excel.
 *
 * Accepts the same query params as {@see \App\Livewire\Dashboard\Finance\Index}
 * (preset, from, to, horizon) so the "Export" button on the page can
 * hand off the current view verbatim.
 */
class FinanceCsvExportController extends Controller
{
    public function __invoke(
        Request $request,
        FinanceSnapshot $snapshot,
        RevenueForecast $forecast,
    ): StreamedResponse {
        [$from, $to] = $this->range($request);
        $horizon = $this->horizon($request);

        $kpis = $snapshot->kpis($from, $to, $horizon);
        $daily = $snapshot->dailySeries($from, $to);
        $channels = $snapshot->channelPerformance($from, $to);
        $forecastSeries = $forecast->project($horizon);
        $posSummary = $snapshot->posSummary($from, $to);
        $posByOutlet = $snapshot->posByOutlet($from, $to);
        $posTopProducts = $snapshot->posTopProducts($from, $to);

        $filename = 'finance-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($from, $to, $horizon, $kpis, $daily, $channels, $forecastSeries, $posSummary, $posByOutlet, $posTopProducts): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            // ── Summary section ──
            fputcsv($out, ['Finance summary']);
            fputcsv($out, ['Range', $from->toDateString().' → '.$to->toDateString()]);
            fputcsv($out, ['Forecast horizon (days)', (string) $horizon]);
            fputcsv($out, []);
            fputcsv($out, ['Metric', 'Value']);
            fputcsv($out, ['Billed', $kpis['billed']]);
            fputcsv($out, ['Cash collected', $kpis['collected']]);
            fputcsv($out, ['Refunded', $kpis['refunded']]);
            fputcsv($out, ['Outstanding (open bookings)', $kpis['outstanding']]);
            fputcsv($out, ['Net (billed - refunded)', $kpis['net']]);
            fputcsv($out, ['Bookings', $kpis['bookings']]);
            fputcsv($out, ['Average booking value', $kpis['avgBookingValue']]);
            fputcsv($out, ['Forecast total (next '.$horizon.'d)', $kpis['forecastTotal']]);
            fputcsv($out, ['Forecast on-books', $kpis['forecastOnBooks']]);
            fputcsv($out, []);

            // ── Channel performance ──
            fputcsv($out, ['Channel performance']);
            fputcsv($out, ['Channel', 'Bookings', 'Revenue', 'Avg value', 'Share %', 'Prev revenue', 'Delta %']);
            foreach ($channels as $row) {
                fputcsv($out, [
                    $row['label'],
                    $row['bookings'],
                    $row['revenue'],
                    $row['avgValue'],
                    $row['share'],
                    $row['prevRevenue'],
                    $row['delta'] ?? '',
                ]);
            }
            fputcsv($out, []);

            // ── Daily series ──
            fputcsv($out, ['Daily series']);
            fputcsv($out, ['Date', 'Billed', 'Collected', 'Refunded']);
            foreach ($daily as $row) {
                fputcsv($out, [$row['date'], $row['billed'], $row['collected'], $row['refunded']]);
            }
            fputcsv($out, []);

            // ── POS sales ──
            fputcsv($out, ['POS summary']);
            fputcsv($out, ['Metric', 'Value']);
            fputcsv($out, ['POS revenue', $posSummary['total']]);
            fputcsv($out, ['Sales count', $posSummary['count']]);
            fputcsv($out, ['Avg ticket', $posSummary['avgTicket']]);
            fputcsv($out, ['Charge-to-room total', $posSummary['chargeToRoomTotal']]);
            fputcsv($out, ['Direct-payment total', $posSummary['directTotal']]);
            fputcsv($out, []);
            fputcsv($out, ['POS by outlet']);
            fputcsv($out, ['Outlet', 'Type', 'Sales', 'Revenue', 'Avg ticket', 'Share %']);
            foreach ($posByOutlet as $row) {
                fputcsv($out, [$row['outlet_name'], $row['outlet_type'], $row['sales_count'], $row['revenue'], $row['avg_ticket'], $row['share']]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Top-selling products']);
            fputcsv($out, ['Product', 'Outlet', 'Units', 'Revenue']);
            foreach ($posTopProducts as $row) {
                fputcsv($out, [$row['product_name'], $row['outlet_name'], $row['units'], $row['revenue']]);
            }
            fputcsv($out, []);

            // ── Forecast series ──
            fputcsv($out, ['Forecast']);
            fputcsv($out, ['Date', 'On-books', 'Projected', 'Is estimate']);
            foreach ($forecastSeries as $row) {
                fputcsv($out, [
                    $row['date'],
                    $row['on_books'],
                    $row['projected'],
                    $row['is_estimate'] ? 'yes' : 'no',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $today = Carbon::today();
        $preset = (string) $request->query('preset', 'last_30d');

        return match ($preset) {
            'today'      => [$today->copy(), $today->copy()],
            'yesterday'  => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_7d'    => [$today->copy()->subDays(6), $today->copy()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [$today->copy()->subMonth()->startOfMonth(), $today->copy()->subMonth()->endOfMonth()],
            'ytd'        => [$today->copy()->startOfYear(), $today->copy()],
            'custom'     => $this->customRange($request, $today),
            default      => [$today->copy()->subDays(29), $today->copy()],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function customRange(Request $request, Carbon $today): array
    {
        try {
            $from = (string) $request->query('from', '');
            $to = (string) $request->query('to', '');
            $fromC = $from !== '' ? Carbon::parse($from)->startOfDay() : $today->copy()->subDays(29);
            $toC = $to !== '' ? Carbon::parse($to)->startOfDay() : $today->copy();
        } catch (Throwable) {
            return [$today->copy()->subDays(29), $today->copy()];
        }
        if ($toC->lessThan($fromC)) {
            $toC = $fromC->copy();
        }

        return [$fromC, $toC];
    }

    private function horizon(Request $request): int
    {
        $h = (int) $request->query('horizon', 30);

        return in_array($h, [30, 60, 90], true) ? $h : 30;
    }
}
