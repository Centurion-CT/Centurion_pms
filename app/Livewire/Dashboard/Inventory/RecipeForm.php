<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Inventory;

use App\Models\Product;
use App\Models\RecipeItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Bill-of-materials editor for a single composite product, e.g. a
 * cocktail that decomposes into a shot of rum + a can of coke.
 *
 * Selling the composite decrements each component (see CompletePosSale)
 * so the ingredient inventory stays accurate without operators tracking
 * each drink assembly manually.
 */
#[Layout('layouts.tenant-auth')]
class RecipeForm extends Component
{
    public int $productId;

    /** @var array<int, array{component_product_id: int|null, quantity: int}> */
    public array $items = [];

    public function mount(Product $product): void
    {
        $this->productId = $product->id;

        $this->items = $product->recipeItems
            ->map(fn (RecipeItem $it) => [
                'component_product_id' => $it->component_product_id,
                'quantity' => $it->quantity,
            ])
            ->all();

        if ($this->items === []) {
            $this->addItem();
        }
    }

    public function addItem(): void
    {
        $this->items[] = ['component_product_id' => null, 'quantity' => 1];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
        if ($this->items === []) {
            $this->addItem();
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'items' => ['array'],
            'items.*.component_product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::query()->findOrFail($this->productId);

        // Guard: a product cannot be a component of itself.
        foreach ($data['items'] as $it) {
            if ((int) $it['component_product_id'] === (int) $product->id) {
                $this->addError('items', 'A product cannot be a component of itself.');

                return;
            }
        }

        DB::transaction(function () use ($product, $data): void {
            // Replace-all is simplest and safest here, recipes are small.
            $product->recipeItems()->delete();
            foreach ($data['items'] as $it) {
                $product->recipeItems()->create([
                    'tenant_id' => $product->tenant_id,
                    'component_product_id' => (int) $it['component_product_id'],
                    'quantity' => (int) $it['quantity'],
                ]);
            }
        });

        session()->flash('status', 'Recipe saved.');
        $this->redirectRoute('tenant.inventory.outlets.show', ['outlet' => $product->outlet_id], navigate: true);
    }

    public function clear(): void
    {
        $product = Product::query()->findOrFail($this->productId);
        $product->recipeItems()->delete();
        session()->flash('status', 'Recipe cleared. This product now sells as a discrete unit again.');
        $this->redirectRoute('tenant.inventory.outlets.show', ['outlet' => $product->outlet_id], navigate: true);
    }

    public function render(): View
    {
        $product = Product::query()->with('outlet')->findOrFail($this->productId);

        // Components come from the same outlet so operators don't have to
        // pick across the whole tenant. Same-outlet scoping matches how
        // real cocktails work, you assemble a Bar drink from Bar stock.
        $candidates = Product::query()
            ->where('outlet_id', $product->outlet_id)
            ->where('id', '!=', $product->id)
            ->where('track_stock', true)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.dashboard.inventory.recipe-form', [
            'product' => $product,
            'candidates' => $candidates,
        ]);
    }
}
