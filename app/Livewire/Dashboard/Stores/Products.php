<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Stores;

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Store products catalog, list, add, edit, archive. This is the
 * master catalog outlets request FROM.
 */
#[Layout('layouts.tenant-auth')]
class Products extends Component
{
    public bool $showArchived = false;

    public function toggleArchived(): void
    {
        $this->showArchived = ! $this->showArchived;
    }

    public function toggleActive(int $productId): void
    {
        $product = Product::query()->findOrFail($productId);
        if ($product->store_id === null) {
            return;
        }

        $product->forceFill(['active' => ! $product->active])->save();

        session()->flash('status', $product->active
            ? "Restored {$product->name}."
            : "Archived {$product->name}.");
    }

    public function render(): View
    {
        $store = Store::query()->orderBy('id')->first();

        $products = $store !== null
            ? Product::query()
                ->where('store_id', $store->id)
                ->when(! $this->showArchived, fn ($q) => $q->where('active', true))
                ->orderBy('name')
                ->get()
            : collect();

        $op = auth('web')->user();
        $role = ($op instanceof Operator ? $op->role : null) ?? OperatorRole::Manager;

        return view('livewire.dashboard.stores.products', [
            'store'           => $store,
            'products'        => $products,
            'canManagePrices' => $role->canManagePrices(),
        ]);
    }
}
