<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Purchasing;

use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * PO detail, line items, per-line outstanding qty, receive UI,
 * status transition buttons (send / cancel).
 */
#[Layout('layouts.tenant-auth')]
class PurchaseOrderShow extends Component
{
    public int $orderId;

    /** @var array<int, int> line_id => qty to receive this pass */
    public array $receipt = [];

    public string $receiptNote = '';

    public function mount(PurchaseOrder $order): void
    {
        $this->orderId = $order->id;
        $this->resetReceiptForm($order);
    }

    private function resetReceiptForm(PurchaseOrder $po): void
    {
        $this->receipt = [];
        foreach ($po->lines as $line) {
            $this->receipt[$line->id] = 0;
        }
        $this->receiptNote = '';
    }

    public function markSent(): void
    {
        $po = PurchaseOrder::query()->findOrFail($this->orderId);
        if ($po->status !== PurchaseOrderStatus::Draft) {
            return;
        }
        $po->update(['status' => PurchaseOrderStatus::Sent, 'placed_at' => now()]);
        session()->flash('status', 'Marked as sent.');
    }

    public function cancel(): void
    {
        $po = PurchaseOrder::query()->findOrFail($this->orderId);
        if ($po->status === PurchaseOrderStatus::Received) {
            return;
        }
        $po->update(['status' => PurchaseOrderStatus::Cancelled, 'cancelled_at' => now()]);
        session()->flash('status', 'Purchase order cancelled.');
    }

    public function receive(ReceivePurchaseOrder $action): void
    {
        $po = PurchaseOrder::query()->with('lines')->findOrFail($this->orderId);
        $map = collect($this->receipt)
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->all();

        if ($map === []) {
            session()->flash('error', 'Enter a quantity to receive on at least one line.');
            return;
        }

        $action->handle(
            po: $po,
            receiptByLineId: $map,
            operator: auth('web')->user(),
            note: $this->receiptNote !== '' ? $this->receiptNote : null,
        );

        session()->flash('status', 'Delivery recorded.');
        $this->resetReceiptForm($po->fresh(['lines']));
    }

    public function receiveAll(ReceivePurchaseOrder $action): void
    {
        $po = PurchaseOrder::query()->with('lines')->findOrFail($this->orderId);
        $action->receiveAll(
            po: $po,
            operator: auth('web')->user(),
            note: $this->receiptNote !== '' ? $this->receiptNote : null,
        );
        session()->flash('status', 'Marked as fully received.');
        $this->resetReceiptForm($po->fresh(['lines']));
    }

    public function render(): View
    {
        $po = PurchaseOrder::query()
            ->with(['supplier', 'outlet', 'operator', 'lines.product'])
            ->findOrFail($this->orderId);

        return view('livewire.dashboard.purchasing.purchase-order-show', [
            'po' => $po,
        ]);
    }
}
