<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Invoices;

use App\Actions\Invoicing\RecordInvoicePayment;
use App\Actions\Invoicing\VoidInvoice;
use App\Models\Invoice;
use App\Models\Operator;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Invoice detail + print view. Wraps the invoice document itself
 * (which is print-optimised via @media rules) with staff-only chrome
 * (mark paid, void, print, back-to-booking).
 */
class Show extends Component
{
    #[Locked]
    public int $invoiceId;

    public string $voidReason = '';

    public int $paymentAmount = 0;

    public string $paymentMethod = 'cash';

    public string $paymentReferenceNote = '';

    public function mount(int $invoice): void
    {
        // Route-binding provides the invoice id as `$invoice`. The
        // BelongsToTenant scope on Invoice keeps us in-tenant.
        $found = Invoice::query()->find($invoice);
        if ($found === null) {
            throw new NotFoundHttpException;
        }
        $this->invoiceId = $found->id;
        // Prefill the record-payment amount with the outstanding balance -
        // most receipts land as a single full payment, and this makes
        // the common path a one-click confirmation.
        $this->paymentAmount = $found->balanceOwed();
    }

    public function void(VoidInvoice $action): void
    {
        try {
            $action->handle($this->invoice(), $this->operator(), $this->voidReason !== '' ? $this->voidReason : null);
            $this->voidReason = '';
            $this->dispatch('notify', title: 'Done', message: 'Invoice voided.', kind: 'ok');
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function recordPayment(RecordInvoicePayment $action): void
    {
        try {
            $action->handle(
                $this->invoice(),
                $this->operator(),
                $this->paymentAmount,
                $this->paymentMethod,
                $this->paymentReferenceNote !== '' ? $this->paymentReferenceNote : null,
            );
            $this->paymentReferenceNote = '';
            $fresh = $this->invoice();
            $this->paymentAmount = $fresh->balanceOwed();
            $this->dispatch('notify', title: 'Done', message: $fresh->balanceOwed() === 0
                ? 'Payment recorded, invoice is now fully paid.'
                : 'Payment recorded.', kind: 'ok');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(): View
    {
        $invoice = $this->invoice();

        return view('livewire.dashboard.invoices.show', [
            'invoice' => $invoice,
            'tenant'  => \App\Models\Tenant::current(),
        ])->layout('layouts.tenant-auth');
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::query()
            ->with(['lines', 'booking.corporate', 'issuedBy', 'payments'])
            ->find($this->invoiceId);
        if ($invoice === null) {
            throw new NotFoundHttpException;
        }

        return $invoice;
    }

    private function operator(): Operator
    {
        /** @var Operator $op */
        $op = auth()->guard('web')->user();

        return $op;
    }
}
