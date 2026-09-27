<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Accounting;

use App\Models\Expense;
use App\Services\Properties\PropertyContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for the expenses list. Honours the same filter set the
 * Livewire index reads (from, to, cat, via, branch) so a shared URL
 * produces the same rows the operator sees on screen.
 */
class ExpenseExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $q = Expense::query()->with(['category:id,name', 'property:id,name', 'operator:id,name']);

        // Branch scope — mirrors Index::baseQuery so on-screen totals
        // and exported totals always agree.
        $branch = (string) $request->query('branch', 'current');
        $pid = app(PropertyContext::class)->current()?->getKey();
        if ($branch === 'hq') {
            $q->whereNull('property_id');
        } elseif ($branch === 'all') {
            // no filter
        } elseif ($pid !== null) {
            $q->where(fn ($qq) => $qq->where('property_id', $pid)->orWhereNull('property_id'));
        }

        if ($request->filled('from')) {
            $q->whereDate('incurred_on', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('incurred_on', '<=', $request->query('to'));
        }
        if ((int) $request->query('cat', 0) > 0) {
            $q->where('expense_category_id', (int) $request->query('cat'));
        }
        if ($request->filled('via')) {
            $q->where('paid_via', (string) $request->query('via'));
        }

        $filename = 'expenses-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($q): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Date', 'Category', 'Description', 'Vendor',
                'Reference', 'Paid via', 'Branch', 'Amount (NGN)',
                'Logged by', 'Notes',
            ]);
            $q->orderByDesc('incurred_on')->orderByDesc('id')
                ->chunk(500, function ($chunk) use ($out): void {
                    foreach ($chunk as $e) {
                        fputcsv($out, [
                            $e->incurred_on->toDateString(),
                            $e->category?->name ?? '',
                            $e->description,
                            $e->vendor ?? '',
                            $e->reference ?? '',
                            ucfirst($e->paid_via),
                            $e->property?->name ?? 'HQ',
                            $e->amount,
                            $e->operator?->name ?? '',
                            $e->notes ?? '',
                        ]);
                    }
                });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
