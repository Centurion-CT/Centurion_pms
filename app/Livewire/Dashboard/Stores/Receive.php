<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Stores;

use App\Actions\Pos\ReceiveStock as ReceiveStockAction;
use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Store-side receive form, supplier delivery lands here. Same shape
 * as the outlet receive component, but the product picker is scoped
 * to store products (products.store_id set). Reuses the existing
 * ReceiveStock action so every row writes a `Received` movement via
 * the single RecordStockMovement choke-point.
 */
#[Layout('layouts.tenant-auth')]
class Receive extends Component
{
    public string $note = '';

    /** @var array<int, array{product_id: int|null, quantity: int, unit_cost: int|null}> */
    public array $lines = [];

    public function mount(): void
    {
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
            'note'                 => ['nullable', 'string', 'max:240'],
            'lines'                => ['array', 'min:1'],
            'lines.*.product_id'   => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity'     => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost'    => ['nullable', 'integer', 'min:0'],
        ]);

        $canManagePrices = $this->currentRole()->canManagePrices();
        $lines = array_values(array_map(fn ($l) => [
            'product_id' => (int) $l['product_id'],
            'quantity'   => (int) $l['quantity'],
            'unit_cost'  => $canManagePrices && $l['unit_cost'] !== null ? (int) $l['unit_cost'] : null,
        ], $this->lines));

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        $count = $receive->handle(
            lines:    $lines,
            operator: $operator,
            note:     $this->note !== '' ? $this->note : null,
        );

        session()->flash('status', "Received {$count} store product".($count === 1 ? '' : 's').'.');
        $this->redirectRoute('tenant.store.index', navigate: true);
    }

    public function render(): View
    {
        $store = Store::query()->orderBy('id')->first();

        $products = $store !== null
            ? Product::query()
                ->where('store_id', $store->id)
                ->where('active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.dashboard.stores.receive', [
            'store'    => $store,
            'products' => $products,
            'canManagePrices' => $this->currentRole()->canManagePrices(),
        ]);
    }

    private function currentRole(): OperatorRole
    {
        $op = auth('web')->user();

        return $op instanceof Operator ? $op->role : OperatorRole::Manager;
    }
}
