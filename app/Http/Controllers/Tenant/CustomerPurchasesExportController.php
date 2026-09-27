<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\PosSaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PosSale;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CSV of every POS purchase tied to a Customer. One row per line item
 * (not per sale) so paid vs comp is visible at the SKU level, which is
 * what the ops team asks for when they audit "what did we give away".
 *
 * Access mirrors the Livewire customer profile: scoped via
 * Customer::forCurrentProperty() so a URL guess into another branch
 * 404s cleanly.
 */
class CustomerPurchasesExportController extends Controller
{
    public function __invoke(Request $request, Customer $customer): StreamedResponse
    {
        $operator = $request->user('web');
        if ($operator === null) {
            throw new NotFoundHttpException();
        }

        // Re-resolve through the property scope so we get a 404 for
        // out-of-branch traversal instead of leaking cross-branch data.
        $customer = Customer::query()->forCurrentProperty()->findOrFail($customer->id);

        $sales = PosSale::query()
            ->where('customer_id', $customer->id)
            ->where('status', PosSaleStatus::Completed->value)
            ->with(['outlet:id,name', 'operator:id,name', 'lines', 'payments'])
            ->orderByDesc('created_at')
            ->get();

        $filename = sprintf(
            'purchases-%s-%s.csv',
            Str::slug($customer->name ?? 'customer'),
            now()->format('Y-m-d'),
        );

        return response()->streamDownload(function () use ($customer, $sales): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['Customer', $customer->name]);
            if ($customer->phone) {
                fputcsv($out, ['Phone', $customer->phone]);
            }
            if ($customer->email) {
                fputcsv($out, ['Email', $customer->email]);
            }
            fputcsv($out, ['Exported at', now()->format('Y-m-d H:i:s')]);
            fputcsv($out, ['Rows are one per line item']);
            fputcsv($out, []);

            fputcsv($out, [
                'Sale ID', 'Date', 'Outlet', 'Cashier', 'Tender(s)',
                'Product', 'Qty', 'Unit price (NGN)', 'Subtotal (NGN)',
                'Line complimentary', 'Sale complimentary', 'Comp reason',
            ]);

            foreach ($sales as $sale) {
                $methods = $sale->payments->pluck('method')->unique()->implode(', ');
                foreach ($sale->lines as $line) {
                    fputcsv($out, [
                        $sale->id,
                        $sale->created_at->format('Y-m-d H:i:s'),
                        $sale->outlet?->name,
                        $sale->operator?->name,
                        $sale->is_complimentary ? 'complimentary' : $methods,
                        $line->name,
                        $line->quantity,
                        $line->unit_price,
                        $line->subtotal,
                        $line->is_complimentary ? 'yes' : 'no',
                        $sale->is_complimentary ? 'yes' : 'no',
                        $sale->is_complimentary ? ($sale->complimentary_reason ?? '') : '',
                    ]);
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
