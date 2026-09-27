<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Purchasing;

use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Composer for a new purchase order, pick supplier + destination outlet,
 * then add one line per SKU with quantity + unit_cost. Saves as Draft
 * (composer default) or Sent (if operator confirms "ordered now").
 *
 * Editing an existing draft is limited to notes + expected date; line
 * changes require deleting the draft and starting fresh. This keeps
 * subtotal / cost-basis integrity simple.
 */
#[Layout('layouts.tenant-auth')]
class PurchaseOrderForm extends Component
{
    public ?int $orderId = null;

    public ?int $supplierId = null;
    public ?int $outletId = null;
    public string $expectedAt = '';
    public string $notes = '';
    public bool $sendNow = false;

    /** @var array<int, array{product_id: int|null, quantity: int, unit_cost: int}> */
    public array $lines = [];

    public function mount(?PurchaseOrder $order = null): void
    {
        if ($order && $order->exists) {
            if ($order->status !== PurchaseOrderStatus::Draft) {
                abort(403, 'Only draft purchase orders can be edited.');
            }
            $this->orderId    = $order->id;
            $this->supplierId = $order->supplier_id;
            $this->outletId   = $order->outlet_id;
            $this->expectedAt = optional($order->expected_at)->toDateString() ?? '';
            $this->notes      = (string) ($order->notes ?? '');
            $this->lines = $order->lines->map(fn ($l) => [
                'product_id' => $l->product_id,
                'quantity'   => $l->quantity_ordered,
                'unit_cost'  => $l->unit_cost,
            ])->all();
        }
        if ($this->lines === []) {
            $this->addLine();
        }
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => null, 'quantity' => 1, 'unit_cost' => 0];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        if ($this->lines === []) {
            $this->addLine();
        }
    }

    public function save(CreatePurchaseOrder $create): void
    {
        $data = $this->validate([
            'supplierId'         => ['required', 'integer', 'exists:suppliers,id'],
            'outletId'           => ['required', 'integer', 'exists:outlets,id'],
            'expectedAt'         => ['nullable', 'date'],
            'notes'              => ['nullable', 'string', 'max:2000'],
            'sendNow'            => ['boolean'],
            'lines'              => ['array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity'   => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost'  => ['required', 'integer', 'min:0'],
        ]);

        if ($this->orderId) {
            // Edit path, only notes / expected date are mutable for drafts.
            $po = PurchaseOrder::query()->findOrFail($this->orderId);
            if ($po->status !== PurchaseOrderStatus::Draft) {
                abort(403);
            }
            $po->update([
                'notes'       => $data['notes'] ?: null,
                'expected_at' => $data['expectedAt'] ?: null,
            ]);
            session()->flash('status', 'Purchase order updated.');
            $this->redirectRoute('tenant.purchasing.orders.show', ['order' => $po->id], navigate: true);
            return;
        }

        $supplier = Supplier::query()->findOrFail($data['supplierId']);
        $outlet   = Outlet::query()->findOrFail($data['outletId']);

        $po = $create->handle(
            supplier: $supplier,
            outlet: $outlet,
            lines: array_map(fn ($l) => [
                'product_id'       => (int) $l['product_id'],
                'quantity_ordered' => (int) $l['quantity'],
                'unit_cost'        => (int) $l['unit_cost'],
            ], $data['lines']),
            operator: auth('web')->user(),
            expectedAt: $data['expectedAt'] ? \Carbon\Carbon::parse($data['expectedAt']) : null,
            notes: $data['notes'] ?: null,
            initialStatus: $data['sendNow'] ? PurchaseOrderStatus::Sent : PurchaseOrderStatus::Draft,
        );

        session()->flash('status', 'Purchase order '.$po->reference.' created.');
        $this->redirectRoute('tenant.purchasing.orders.show', ['order' => $po->id], navigate: true);
    }

    public function render(): View
    {
        $suppliers = Supplier::query()->active()->orderBy('name')->get();
        $outlets = Outlet::query()->orderBy('name')->get();

        $products = collect();
        if ($this->outletId) {
            $products = Product::query()
                ->where('outlet_id', $this->outletId)
                ->where('track_stock', true)
                ->where('active', true)
                ->orderBy('name')
                ->get();
        }

        $subtotal = 0;
        foreach ($this->lines as $l) {
            $subtotal += (int) ($l['quantity'] ?? 0) * (int) ($l['unit_cost'] ?? 0);
        }

        return view('livewire.dashboard.purchasing.purchase-order-form', [
            'suppliers' => $suppliers,
            'outlets'   => $outlets,
            'products'  => $products,
            'subtotal'  => $subtotal,
        ]);
    }
}
