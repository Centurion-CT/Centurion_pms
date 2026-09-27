<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Stores;

use App\Actions\Inventory\RecordStockIssuance;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Ad-hoc issue from store → outlet. Pick a store product, an outlet,
 * the outlet's product to receive the stock, and a quantity.
 *
 * For MVP the outlet-product picker is a raw dropdown (list of the
 * chosen outlet's tracked products); a future iteration can suggest
 * a match by name/SKU. No requisition step, an issue is direct.
 */
#[Layout('layouts.tenant-auth')]
class IssueForm extends Component
{
    public ?int $storeProductId = null;
    public ?int $outletId = null;
    public ?int $outletProductId = null;
    public int $quantity = 1;
    public string $note = '';

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'storeProductId'   => 'required|integer|exists:products,id',
            'outletId'         => 'required|integer|exists:outlets,id',
            'outletProductId'  => 'required|integer|exists:products,id',
            'quantity'         => 'required|integer|min:1',
            'note'             => 'nullable|string|max:500',
        ];
    }

    public function updatedOutletId(): void
    {
        // Reset the outlet product when outlet changes so a stale
        // product-id from a different outlet can't land in the form.
        $this->outletProductId = null;
    }

    public function submit(RecordStockIssuance $issue): void
    {
        $this->validate();

        $storeProduct = Product::query()->findOrFail($this->storeProductId);
        $outletProduct = Product::query()->findOrFail($this->outletProductId);

        if ($outletProduct->outlet_id !== $this->outletId) {
            $this->addError('outletProductId', 'Product does not belong to the selected outlet.');

            return;
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        try {
            $issue->handle(
                storeProduct:  $storeProduct,
                outletProduct: $outletProduct,
                quantity:      $this->quantity,
                operator:      $operator,
                note:          $this->note !== '' ? $this->note : null,
            );
        } catch (Throwable $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        }

        session()->flash('flash.banner', "Issued {$this->quantity} {$storeProduct->name} to {$outletProduct->outlet->name}.");
        session()->flash('flash.bannerStyle', 'success');

        $this->redirectRoute('tenant.store.index', navigate: true);
    }

    public function render(): View
    {
        $store = Store::query()->orderBy('id')->first();

        $storeProducts = $store !== null
            ? Product::query()
                ->where('store_id', $store->id)
                ->where('active', true)
                ->where('track_stock', true)
                ->where('quantity_on_hand', '>', 0)
                ->orderBy('name')
                ->get()
            : collect();

        $outlets = Outlet::query()->active()->orderBy('name')->get();

        $outletProducts = $this->outletId !== null
            ? Product::query()
                ->where('outlet_id', $this->outletId)
                ->where('active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.dashboard.stores.issue-form', [
            'store'          => $store,
            'storeProducts'  => $storeProducts,
            'outlets'        => $outlets,
            'outletProducts' => $outletProducts,
        ]);
    }
}
