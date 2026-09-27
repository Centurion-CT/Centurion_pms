<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Renders a guest's wallet statement as a downloadable PDF invoice.
 *
 * Query params:
 *   from=YYYY-MM-DD   optional lower bound (inclusive) on transaction date
 *   to=YYYY-MM-DD     optional upper bound (inclusive)
 *
 * Route sits under `role:customers` — the same gate that guards the
 * customer profile page. Route-model binding + Customer's
 * BelongsToTenant scope keeps cross-tenant access to a 404.
 */
class CustomerWalletStatementPdfController extends Controller
{
    public function download(Request $request, Customer $customer): Response
    {
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        $customer->loadMissing('wallet');

        $query = CustomerWalletTransaction::query()
            ->where('customer_id', $customer->id)
            ->orderBy('id');

        if ($from !== null) {
            $query->where('created_at', '>=', $from->copy()->startOfDay());
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to->copy()->endOfDay());
        }

        $transactions = $query->get();

        $openingBalance = 0;
        if ($from !== null) {
            $priorSum = (int) CustomerWalletTransaction::query()
                ->where('customer_id', $customer->id)
                ->where('created_at', '<', $from->copy()->startOfDay())
                ->sum('amount_naira');
            $openingBalance = $priorSum;
        }

        $inflow = $transactions->where('amount_naira', '>', 0)->sum('amount_naira');
        $outflow = $transactions->where('amount_naira', '<', 0)->sum('amount_naira');
        $totals = [
            'inflow' => is_numeric($inflow) ? (int) $inflow : 0,
            'outflow' => is_numeric($outflow) ? abs((int) $outflow) : 0,
        ];

        $pdf = Pdf::loadView('pdfs.customer-wallet-statement', [
            'customer' => $customer,
            'tenant' => Tenant::current(),
            'transactions' => $transactions,
            'from' => $from,
            'to' => $to,
            'openingBalance' => $openingBalance,
            'closingBalance' => $customer->walletBalance(),
            'totals' => $totals,
            'generatedAt' => Carbon::now(),
        ])->setPaper('a4');

        $filename = sprintf(
            'wallet-statement-%s-%s.pdf',
            $customer->id,
            Carbon::now()->format('Y-m-d'),
        );

        return $pdf->download($filename);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
