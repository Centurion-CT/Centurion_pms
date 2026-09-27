<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Inventory;

use App\Actions\Pos\ReceiveStock as ReceiveStockAction;
use App\Enums\OperatorRole;
use App\Models\Outlet;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Bulk stock-in form. Rows: [product_id, quantity, unit_cost?].
 * Every line writes an individual movement so the audit trail
 * captures the exact receipt.
 */
#[Layout('layouts.tenant-auth')]
class ReceiveStock extends Component
{
    public int $outletId;

    public string $note = '';

    /** @var array<int, array{product_id: int|null, quantity: int, unit_cost: int|null}> */
    public array $lines = [];

    public function mount(Outlet $outlet): void
    {
        $this->outletId = $outlet->id;
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => null, 'quantity' => 0, 'unit_cost' => null];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        if (empty($this->lines)) {
            $this->addLine();
        }
    }

    public function save(ReceiveStockAction $receive): void
    {
        $this->validate([
            'note' => ['nullable', 'string', 'max:240'],
            'lines' => ['array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['nullable', 'integer', 'min:0'],
        ]);

        // Clerks can bump quantities but can't reset the cost basis, a
        // null unit_cost tells the ReceiveStock action to leave
        // Product.cost alone.
        $canManagePrices = $this->currentRole()->canManagePrices();
        $lines = array_values(array_map(fn ($l) => [
            'product_id' => (int) $l['product_id'],
            'quantity'   => (int) $l['quantity'],
            'unit_cost'  => $canManagePrices && $l['unit_cost'] !== null ? (int) $l['unit_cost'] : null,
        ], $this->lines));

        $count = $receive->handle(
            lines: $lines,
            operator: auth('web')->user(),
            note: $this->note !== '' ? $this->note : null,
        );

        session()->flash('status', "Received stock on {$count} product".($count === 1 ? '' : 's').'.');
        $this->redirectRoute('tenant.inventory.outlets.show', ['outlet' => $this->outletId], navigate: true);
    }

    public function render(): View
    {
        $outlet = Outlet::query()->findOrFail($this->outletId);
        $products = $outlet->products()->where('track_stock', true)->where('active', true)->orderBy('name')->get();

        return view('livewire.dashboard.inventory.receive-stock', [
            'outlet' => $outlet,
            'products' => $products,
            'canManagePrices' => $this->currentRole()->canManagePrices(),
        ]);
    }

    private function currentRole(): OperatorRole
    {
        $op = auth('web')->user();

        return ($op instanceof \App\Models\Operator ? $op->role : null) ?? OperatorRole::Manager;
    }
}
