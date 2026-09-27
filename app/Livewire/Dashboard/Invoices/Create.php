<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Invoices;

use App\Actions\Invoicing\CreateInvoice;
use App\Actions\Invoicing\SuggestInvoiceLines;
use App\Enums\InvoiceLineType;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Front-desk invoice composer. Loads a booking, prefills a proposed line
 * set (all nights + unsettled charges + non-complimentary packages) via
 * SuggestInvoiceLines, and lets staff add/remove/edit lines before
 * issuing.
 *
 * Deliberately no auto-save, staff review the whole thing, hit Issue,
 * then the invoice becomes immutable + numbered.
 */
class Create extends Component
{
    #[Locked]
    public int $bookingId;

    /**
     * @var list<array{
     *   line_type: string,
     *   source_type: ?string,
     *   source_id: ?int,
     *   description: string,
     *   quantity: int,
     *   unit_price: int,
     *   line_total: int,
     * }>
     */
    public array $lines = [];

    public string $notes = '';

    public string $dueAt = '';

    public int $depositApplied = 0;

    public function mount(string $bookingRef, SuggestInvoiceLines $suggest): void
    {
        $booking = Booking::query()
            ->with(['selectedPackages', 'room', 'charges'])
            ->where('booking_ref', $bookingRef)
            ->first();
        if ($booking === null) {
            throw new NotFoundHttpException;
        }
        $this->bookingId = $booking->id;
        $this->lines = $suggest->handle($booking);
        // Prefill "already paid" from the actual Payment ledger, not
        // just booking.deposit_amount. deposit_amount is only set on
        // the on-hold flow — a guest who walked in and paid the full
        // total at check-in has deposit_amount = 0 but a Succeeded
        // Full/Balance payment row for the whole amount. Refunded
        // amounts are subtracted so a partial refund shows the net.
        $succeeded = (int) $booking->payments()
            ->where('status', PaymentStatus::Succeeded->value)
            ->sum('amount');
        $refunded = (int) $booking->payments()
            ->where('status', PaymentStatus::Refunded->value)
            ->sum('amount');
        $this->depositApplied = max(0, $succeeded - $refunded);
    }

    /**
     * Re-synthesise the line set from the booking's current state.
     * Useful when a charge lands on the folio after this page was
     * opened — clicking Regenerate pulls the fresh set instead of
     * making the operator refresh the page + lose the notes / due
     * date they already typed.
     */
    public function regenerate(SuggestInvoiceLines $suggest): void
    {
        $this->lines = $suggest->handle($this->booking());
        $this->dispatch('notify',
            title: 'Refreshed',
            message: 'Line items pulled from the latest booking state.',
            kind: 'ok',
        );
    }

    public function subtotal(): int
    {
        $sum = 0;
        foreach ($this->lines as $line) {
            $sum += (int) ($line['line_total'] ?? 0);
        }

        return $sum;
    }

    public function balanceOwed(): int
    {
        return max(0, $this->subtotal() - $this->depositApplied);
    }

    public function issue(CreateInvoice $action): mixed
    {
        if ($this->lines === []) {
            $this->dispatch('notify', title: 'Couldn\'t issue', message: 'Add at least one line before issuing.', kind: 'err');

            return null;
        }
        foreach ($this->lines as $i => $line) {
            if (trim((string) $line['description']) === '') {
                throw ValidationException::withMessages(["lines.{$i}.description" => 'Description is required.']);
            }
            // Negative unit prices are legit for auto-generated discount
            // rows (corporate rate line, future refunds). We only used
            // to refuse them because the form let staff type a value —
            // the field is now read-only + only synthesised server-side.
        }

        try {
            $invoice = $action->handle(
                tenant: Tenant::current() ?? throw new RuntimeException('No tenant context.'),
                booking: $this->booking(),
                operator: $this->operator(),
                lines: $this->lines,
                notes: $this->notes !== '' ? $this->notes : null,
                dueAt: $this->dueAt !== '' ? \Illuminate\Support\Carbon::parse($this->dueAt) : null,
                issue: true,
                initialAmountPaid: $this->depositApplied,
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t issue', message: $e->getMessage(), kind: 'err');

            return null;
        }

        return $this->redirectRoute('tenant.invoices.show', ['invoice' => $invoice->id], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.dashboard.invoices.create', [
            'booking'  => $this->booking(),
            'subtotal' => $this->subtotal(),
            'balance'  => $this->balanceOwed(),
        ])->layout('layouts.tenant-auth');
    }

    private function booking(): Booking
    {
        $booking = Booking::query()->with(['selectedPackages', 'room', 'charges'])->find($this->bookingId);
        if ($booking === null) {
            throw new NotFoundHttpException;
        }

        return $booking;
    }

    private function operator(): Operator
    {
        /** @var Operator $op */
        $op = auth()->guard('web')->user();

        return $op;
    }
}
