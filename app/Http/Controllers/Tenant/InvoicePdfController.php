<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Renders an invoice as a downloadable PDF via dompdf.
 *
 * The Blade template at resources/views/invoices/pdf.blade.php mirrors
 * the printable invoice document in Invoices/Show but uses table-based
 * layout + inline styles so dompdf (which has patchy Flexbox / Grid
 * support) can render it faithfully.
 *
 * Route sits inside the same `role:bookings` group as the Livewire
 * show / index — whoever can see the invoice can download it.
 */
class InvoicePdfController extends Controller
{
    public function download(Invoice $invoice): Response
    {
        // Tenant scope on Invoice's BelongsToTenant guards cross-tenant
        // access — if the id doesn't belong to the current tenant, the
        // route-model binding above returns 404 before we get here.

        $invoice->load(['lines', 'booking.corporate', 'payments', 'issuedBy']);

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => Tenant::current(),
        ])->setPaper('a4');

        $filename = ($invoice->number ?? 'invoice-draft-'.$invoice->id).'.pdf';

        return $pdf->download($filename);
    }
}
