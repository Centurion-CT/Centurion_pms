<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Supplies;

use App\Actions\Inventory\ApplyHousekeepingTurnover;
use App\Actions\Inventory\CancelStockRequest;
use App\Actions\Inventory\CreateStockRequest;
use App\Actions\Inventory\ReceiveFromLaundry;
use App\Actions\Inventory\SendToLaundry;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Read + request view of one Housekeeping outlet, for Front desk +
 * House cleaners + Supervisors who need to know what's on hand and
 * ask for more without holding full inventory admin rights.
 *
 * Deliberately narrower than {@see \App\Livewire\Dashboard\Inventory\OutletShow}:
 * no edit outlet, no receive-direct, no product create / delete. Only
 * viewing the daily sheet + filing a store request. Ownership check
 * on mount rejects non-Housekeeping outlets so the route can't be
 * used to sneak into a bar or kitchen with cleaner-level rights.
 */
#[Layout('layouts.tenant-auth')]
class Show extends Component
{
    public int $outletId;

    public ?int $requestStoreProductId = null;

    public int $requestQuantity = 0;

    public string $requestNote = '';

    public bool $requestModalOpen = false;

    /** Turnover modal state. */
    public bool $turnoverModalOpen = false;

    public int $turnoverRooms = 1;

    public string $turnoverNote = '';

    /** Per-product laundry action drafts, keyed by product id.
     * Separate send/receive qty fields so a misclick between → laundry
     * and ← back can't record the wrong movement. */
    /** @var array<int, array{send: string, receive: string}> */
    public array $laundryDrafts = [];

    public function mount(Outlet $outlet): void
    {
        if ($outlet->type !== OutletType::Housekeeping) {
            // Supplies only surfaces housekeeping outlets. A non-housekeeping
            // outlet here means an operator is deep-linking past the module
            // boundary — 404 rather than leak a bar's stock sheet.
            throw new NotFoundHttpException();
        }
        $this->outletId = $outlet->id;
    }

    public function openRequest(?int $storeProductId = null): void
    {
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

    /* ── Turnover consumption ───────────────────────────────── */

    public function openTurnover(): void
    {
        $this->turnoverRooms = 1;
        $this->turnoverNote = '';
        $this->turnoverModalOpen = true;
    }

    public function cancelTurnover(): void
    {
        $this->turnoverModalOpen = false;
    }

    public function logTurnover(ApplyHousekeepingTurnover $apply): void
    {
        $this->validate([
            'turnoverRooms' => ['required', 'integer', 'min:1', 'max:200'],
            'turnoverNote'  => ['nullable', 'string', 'max:120'],
        ]);

        $outlet = Outlet::query()->findOrFail($this->outletId);
        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        try {
            $summary = $apply->handle(
                outlet:       $outlet,
                roomsCleaned: $this->turnoverRooms,
                noteRef:      $this->turnoverNote !== '' ? $this->turnoverNote : null,
                operator:     $operator,
            );
        } catch (Throwable $e) {
            $this->addError('turnoverRooms', $e->getMessage());

            return;
        }

        session()->flash(
            'status',
            $summary['lines_moved'] === 0
                ? 'No items to deduct — set usage per clean on at least one product first.'
                : "Deducted {$summary['total_units']} units across {$summary['lines_moved']} products.",
        );
        $this->turnoverModalOpen = false;
    }

    /* ── Laundry rotation ───────────────────────────────────── */

    public function sendToLaundry(int $productId, SendToLaundry $send): void
    {
        $draft = $this->laundryDrafts[$productId] ?? ['send' => '', 'receive' => ''];
        $qty = (int) ($draft['send'] ?? '');

        $product = $this->requireOutletProduct($productId);
        if ($product === null) {
            return;
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        try {
            $send->handle(product: $product, quantity: $qty, operator: $operator);
        } catch (Throwable $e) {
            $this->addError('laundryDrafts.'.$productId, $e->getMessage());

            return;
        }

        $this->laundryDrafts[$productId]['send'] = '';
        session()->flash('status', "Sent {$qty} × {$product->name} to laundry.");
    }

    public function receiveFromLaundry(int $productId, ReceiveFromLaundry $receive): void
    {
        $draft = $this->laundryDrafts[$productId] ?? ['send' => '', 'receive' => ''];
        $qty = (int) ($draft['receive'] ?? '');

        $product = $this->requireOutletProduct($productId);
        if ($product === null) {
            return;
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        try {
            $receive->handle(product: $product, quantity: $qty, operator: $operator);
        } catch (Throwable $e) {
            $this->addError('laundryDrafts.'.$productId, $e->getMessage());

            return;
        }

        $this->laundryDrafts[$productId]['receive'] = '';
        session()->flash('status', "Received {$qty} × {$product->name} back from laundry.");
    }

    /**
     * Manager-only: adjust the per-clean usage rate on a product so
     * the turnover action deducts the right amount. Rejects non-manager
     * roles up front so a HouseCleaner can't inflate consumption.
     */
    public function setUsagePerClean(int $productId, int $usage): void
    {
        $product = $this->requireOutletProduct($productId);
        if ($product === null) {
            return;
        }
        if (! $this->currentRole()->canManagePrices()) {
            $this->addError('laundryDrafts.'.$productId, 'Manager-only.');

            return;
        }
        if ($usage < 0 || $usage > 999) {
            $this->addError('laundryDrafts.'.$productId, 'Usage must be 0–999.');

            return;
        }

        $product->usage_per_clean = $usage;
        $product->save();
        session()->flash('status', "Updated usage per clean for {$product->name} to {$usage}.");
    }

    /**
     * Manager-only: toggle a product between consumable and rotational.
     * Turning off rotational when there are units still in_use or at
     * the laundry is rejected — the state has to be reconciled first
     * so units don't silently disappear from the count.
     */
    public function toggleRotational(int $productId): void
    {
        $product = $this->requireOutletProduct($productId);
        if ($product === null) {
            return;
        }
        if (! $this->currentRole()->canManagePrices()) {
            $this->addError('laundryDrafts.'.$productId, 'Manager-only.');

            return;
        }

        $newFlag = ! $product->is_rotational;
        if (! $newFlag && ($product->qty_in_use > 0 || $product->qty_at_laundry > 0)) {
            $this->addError(
                'laundryDrafts.'.$productId,
                'Cannot turn off rotational — reconcile in-use / at-laundry counts first.',
            );

            return;
        }

        $product->is_rotational = $newFlag;
        $product->save();
        session()->flash(
            'status',
            "{$product->name} is now ".($newFlag ? 'rotational.' : 'consumable.'),
        );
    }

    private function requireOutletProduct(int $productId): ?Product
    {
        $product = Product::query()->find($productId);
        if ($product === null || $product->outlet_id !== $this->outletId) {
            $this->addError('laundryDrafts.'.$productId, 'Unknown product.');

            return null;
        }

        return $product;
    }

    private function currentRole(): OperatorRole
    {
        $op = auth('web')->user();

        return ($op instanceof Operator ? $op->role : null) ?? OperatorRole::Manager;
    }

    public function render(DailyStockSheet $sheet): View
    {
        $outlet = Outlet::query()->with('property')->findOrFail($this->outletId);
        $usesCentralStore = $this->usesCentralStore();

        $rows = $sheet->build(Carbon::today(), outletId: $this->outletId);

        $pendingRequests = $usesCentralStore
            ? StockRequest::query()
                ->pending()
                ->where('outlet_id', $this->outletId)
                ->with(['requestedBy:id,name', 'product:id,name'])
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $outletProductIds = $outlet->products()->pluck('id');
        $recentMovements = StockMovement::query()
            ->whereIn('product_id', $outletProductIds)
            ->with(['product:id,name', 'operator:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(15)
            ->get();

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

        // Per-department properties can't request from store — they
        // restock via a direct supplier delivery on the outlet page.
        // Cleaners / front desk don't hold that permission, only
        // Manager / Inventory clerk / Stock keeper do; flag it so
        // the view can render "Log a delivery" vs "ask a Manager".
        $op = auth('web')->user();
        $canReceiveStock = $op instanceof Operator
            ? $op->canAccessModule('inventory')
            : false;

        return view('livewire.dashboard.supplies.show', [
            'outlet'                => $outlet,
            'rows'                  => $rows,
            'today'                 => Carbon::today(),
            'pendingRequests'       => $pendingRequests,
            'pendingByStoreProduct' => $pendingRequests->groupBy('product_id'),
            'recentMovements'       => $recentMovements,
            'storeProducts'         => $storeProducts,
            'usesCentralStore'      => $usesCentralStore,
            'canManagePrices'       => $this->currentRole()->canManagePrices(),
            'canReceiveStock'       => $canReceiveStock,
        ]);
    }

    private function usesCentralStore(): bool
    {
        $property = Tenant::current()?->property;
        $mode = $property?->stock_model ?? StockModel::StoreAndRequisition;

        return $mode->usesCentralStore();
    }
}
