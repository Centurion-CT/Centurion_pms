<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Inventory;

use App\Actions\Inventory\CancelStockRequest;
use App\Actions\Inventory\CreateStockRequest;
use App\Actions\Inventory\DeleteOutlet;
use App\Actions\Inventory\DeleteProduct;
use App\Enums\OperatorRole;
use App\Enums\StockModel;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Inventory\DailyStockSheet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Outlet detail, daily stock sheet (opening / supply / total / sold /
 * price / total ₦ / balance / remark). Balance is derived from the
 * ledger, not entered by hand; Remark auto-classifies.
 *
 * Outlets can't create products or adjust stock directly. The store is
 * the master catalog: to bring a SKU into an outlet, file a request
 * against the store product; approval auto-provisions the outlet-side
 * product (if it doesn't exist yet) and issues stock.
 */
#[Layout('layouts.tenant-auth')]
class OutletShow extends Component
{
    public int $outletId;

    /** Request-modal state. */
    public ?int $requestStoreProductId = null;

    public int $requestQuantity = 0;

    public string $requestNote = '';

    public bool $requestModalOpen = false;

    public function mount(Outlet $outlet): void
    {
        $this->outletId = $outlet->id;
    }

    public function openRequest(?int $storeProductId = null): void
    {
        // Requisitions are meaningless in per_department mode, the
        // outlet receives stock directly. Guard the action so a stray
        // wire:call can't open the modal even if the button's hidden.
        if (! $this->usesCentralStore()) {
            return;
        }
        $this->requestStoreProductId = $storeProductId;
        $this->requestQuantity = 0;
        $this->requestNote = '';
        $this->requestModalOpen = true;
    }

    public function cancelRequestModal(): void
    {
        $this->requestStoreProductId = null;
        $this->requestQuantity = 0;
        $this->requestNote = '';
        $this->requestModalOpen = false;
    }

    public function submitRequest(CreateStockRequest $create): void
    {
        if (! $this->usesCentralStore()) {
            $this->addError('requestQuantity', 'This property runs on per-department inventory, requisitions are disabled.');

            return;
        }

        $this->validate([
            'requestStoreProductId' => ['required', 'integer', 'exists:products,id'],
            'requestQuantity'       => ['required', 'integer', 'min:1'],
            'requestNote'           => ['nullable', 'string', 'max:240'],
        ]);

        $outlet = Outlet::query()->findOrFail($this->outletId);
        $storeProduct = Product::query()->findOrFail($this->requestStoreProductId);

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        try {
            $create->handle(
                storeProduct: $storeProduct,
                outlet:       $outlet,
                quantity:     $this->requestQuantity,
                requestedBy:  $operator,
                note:         $this->requestNote !== '' ? $this->requestNote : null,
            );
        } catch (Throwable $e) {
            $this->addError('requestQuantity', $e->getMessage());

            return;
        }

        session()->flash('status', "Requested {$this->requestQuantity} × {$storeProduct->name} from the store.");
        $this->cancelRequestModal();
    }

    public function cancelRequest(int $requestId, CancelStockRequest $cancel): void
    {
        if (! $this->usesCentralStore()) {
            return;
        }
        $request = StockRequest::query()->findOrFail($requestId);
        if ((int) $request->outlet_id !== (int) $this->outletId) {
            return;
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        try {
            $cancel->handle($request, $operator);
        } catch (Throwable $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        session()->flash('status', "Request #{$requestId} cancelled.");
    }

    /**
     * Manager-only: delete this outlet. FK cascades sweep the
     * products / stock movements / sales blast radius; we log the
     * summary of what was cascaded to activity_log for audit. On
     * success, redirect back to the outlets picker since the
     * detail page no longer has a subject.
     */
    public function deleteOutlet(DeleteOutlet $action): void
    {
        try {
            $outlet = Outlet::query()->findOrFail($this->outletId);
            $op = auth('web')->user();
            if (! $op instanceof Operator) {
                throw new \RuntimeException('Not signed in.');
            }
            $summary = $action->handle($outlet, $op);
            session()->flash('status', sprintf(
                'Outlet "%s" deleted (%d product(s), %d sale(s) cascaded).',
                $summary['outlet_name'],
                $summary['products'],
                $summary['sales'],
            ));
            $this->redirectRoute('tenant.pos.pick-outlet', navigate: true);
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t delete outlet', message: $e->getMessage(), kind: 'err');
        }
    }

    /**
     * Manager-only: delete one product on this outlet's catalogue.
     * Stock movements + open stock requests + purchase-order lines
     * cascade; historical pos_sale_lines set product_id NULL so the
     * receipts survive. Stays on the outlet page after delete.
     */
    public function deleteProduct(int $productId, DeleteProduct $action): void
    {
        try {
            $product = Product::query()->findOrFail($productId);
            $op = auth('web')->user();
            if (! $op instanceof Operator) {
                throw new \RuntimeException('Not signed in.');
            }
            if ($product->outlet_id !== $this->outletId) {
                throw new \RuntimeException('That product doesn\'t belong to this outlet.');
            }
            $summary = $action->handle($product, $op);
            $this->dispatch('notify',
                title: 'Product deleted',
                message: sprintf('"%s" removed (%d movement(s) cascaded).',
                    $summary['product_name'], $summary['movements']),
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t delete product', message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(DailyStockSheet $sheet): View
    {
        $outlet = Outlet::query()->with('property')->findOrFail($this->outletId);
        $usesCentralStore = $this->usesCentralStore();

        // Sheet rows for today (Balance + Remark are display-only -
        // service already computes both from the ledger).
        $rows = $sheet->build(Carbon::today(), outletId: $this->outletId);

        // Pending requests filed by this outlet; keyed by store product
        // so each outlet-sheet row can show a pending badge if there's
        // a request in flight for it. Skip the query entirely in
        // per_department mode, no requisition ever exists there.
        $pendingRequests = $usesCentralStore
            ? StockRequest::query()
                ->pending()
                ->where('outlet_id', $this->outletId)
                ->with(['requestedBy:id,name', 'product:id,name'])
                ->orderByDesc('created_at')
                ->get()
            : collect();

        // Recent movements audit, same behaviour as before, scoped
        // to any product touching this outlet.
        $outletProductIds = $outlet->products()->pluck('id');
        $recentMovements = StockMovement::query()
            ->whereIn('product_id', $outletProductIds)
            ->with(['product:id,name', 'operator:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(15)
            ->get();

        // Store products the operator can request FROM. Only relevant
        // in store_and_requisition mode.
        $storeProducts = collect();
        if ($usesCentralStore) {
            $store = Store::query()
                ->where('tenant_id', $outlet->tenant_id)
                ->orderBy('id')
                ->first();
            $storeProducts = $store !== null
                ? Product::query()
                    ->where('store_id', $store->id)
                    ->where('active', true)
                    ->where('track_stock', true)
                    ->orderBy('name')
                    ->get()
                : collect();
        }

        $op = auth('web')->user();
        $role = ($op instanceof Operator ? $op->role : null) ?? OperatorRole::Manager;

        return view('livewire.dashboard.inventory.outlet-show', [
            'outlet'           => $outlet,
            'rows'             => $rows,
            'today'            => Carbon::today(),
            'pendingRequests'  => $pendingRequests,
            'pendingByStoreProduct' => $pendingRequests->groupBy('product_id'),
            'recentMovements'  => $recentMovements,
            'storeProducts'    => $storeProducts,
            'canManagePrices'  => $role->canManagePrices(),
            'usesCentralStore' => $usesCentralStore,
        ]);
    }

    /**
     * True when the current property runs on `store_and_requisition`.
     * Cheap to call multiple times, reads a cast enum off the
     * already-loaded tenant.
     */
    private function usesCentralStore(): bool
    {
        $property = Tenant::current()?->property;
        $mode = $property?->stock_model ?? StockModel::StoreAndRequisition;

        return $mode->usesCentralStore();
    }
}
