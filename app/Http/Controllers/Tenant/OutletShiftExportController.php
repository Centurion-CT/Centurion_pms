<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\OperatorRole;
use App\Http\Controllers\Controller;
use App\Models\OutletShift;
use App\Services\Pos\ShiftReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CSV export of a single shift report. Route-model bound on {shift};
 * access mirrors the ShiftShow Livewire page (Manager sees any shift
 * on outlets in-scope, PosStaff only their own).
 *
 * Streams the CSV directly to the browser so a huge product list can
 * still export without pinning memory.
 */
class OutletShiftExportController extends Controller
{
    public function __invoke(
        Request $request,
        OutletShift $shift,
        ShiftReportBuilder $builder,
    ): StreamedResponse {
        $operator = $request->user('web');
        if ($operator === null) {
            throw new NotFoundHttpException();
        }
        $isManager = $operator->role === OperatorRole::Manager;
        $isOwn = $shift->operator_id === $operator->id;
        if (! $isManager && ! $isOwn) {
            throw new NotFoundHttpException();
        }

        $shift->load(['outlet', 'operator', 'closedBy', 'snapshots']);
        $report = $builder->build($shift);

        $filename = sprintf(
            'shift-%s-%s-%s.csv',
            Str::slug($shift->outlet?->name ?? 'outlet'),
            Str::slug($shift->operator?->name ?? 'operator'),
            $shift->opened_at->format('Y-m-d-Hi'),
        );

        return response()->streamDownload(function () use ($shift, $report): void {
            $out = fopen('php://output', 'wb');

            // Header block — machine-parseable but keyed for humans.
            fputcsv($out, ['Shift ID', $shift->id]);
            fputcsv($out, ['Outlet', $shift->outlet?->name]);
            fputcsv($out, ['Operator', $shift->operator?->name]);
            fputcsv($out, ['Opened at', $report['window']['opened_at']->format('Y-m-d H:i:s')]);
            fputcsv($out, ['Closed at', $shift->closed_at?->format('Y-m-d H:i:s') ?? 'STILL OPEN']);
            if ($shift->closedBy) {
                fputcsv($out, ['Closed by', $shift->closedBy->name]);
            }
            if ($shift->close_reason) {
                fputcsv($out, ['Close reason', $shift->close_reason]);
            }
            if ($shift->notes) {
                fputcsv($out, ['Notes', $shift->notes]);
            }
            fputcsv($out, []);

            // Sales summary.
            fputcsv($out, ['SALES SUMMARY']);
            fputcsv($out, ['Sales', $report['sales']['count']]);
            fputcsv($out, ['Gross', $report['sales']['gross']]);
            fputcsv($out, ['Net (after voids)', $report['sales']['net']]);
            fputcsv($out, ['Voided sales', $report['sales']['voided_count']]);
            fputcsv($out, ['Voided amount', $report['sales']['voided_amount']]);
            fputcsv($out, ['Complimentary sales', $report['sales']['comp_count']]);
            fputcsv($out, ['Complimentary value', $report['sales']['comp_value']]);
            fputcsv($out, ['Average ticket', $report['sales']['avg_ticket']]);
            fputcsv($out, []);

            // Per-tender rollup.
            fputcsv($out, ['BY TENDER']);
            fputcsv($out, ['Method', 'Count', 'Amount (NGN)']);
            foreach ($report['tenders'] as $t) {
                fputcsv($out, [$t['method'], $t['count'], $t['amount']]);
            }
            fputcsv($out, []);

            // Stock reconciliation table.
            fputcsv($out, ['STOCK RECONCILIATION']);
            fputcsv($out, [
                'Product', 'Opening qty', 'Sold in shift', 'Other movements',
                'Expected closing', 'Reported closing', 'Variance', 'Unit price (NGN)',
            ]);
            foreach ($report['stock'] as $row) {
                fputcsv($out, [
                    $row['name'],
                    $row['opening_qty'],
                    $row['sold_qty'],
                    $row['other_delta'],
                    $row['expected_closing'],
                    $row['closing_qty'] ?? 'STILL OPEN',
                    $row['variance'] ?? '',
                    $row['unit_price'] ?? '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
