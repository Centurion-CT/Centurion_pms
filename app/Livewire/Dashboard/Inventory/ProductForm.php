<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Inventory;

use App\Actions\Pos\RecordStockMovement;
use App\Enums\OperatorRole;
use App\Enums\StockMovementReason;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Create / edit a product. On create with a positive `initial_stock`,
 * records an InitialStock movement so the ledger reflects where the
 * quantity came from.
 */
#[Layout('layouts.tenant-auth')]
class ProductForm extends Component
{
    public ?int $productId = null;

    public ?int $outletId = null;

    public string $name = '';

    public string $sku = '';

    public string $barcode = '';

    public string $description = '';

    public int $price = 0;

    public int $cost = 0;

    public bool $track_stock = true;

    public int $initial_stock = 0;

    public int $low_stock_threshold = 0;

    public bool $active = true;

    public function mount(?Product $product = null, ?Outlet $outlet = null): void
    {
        if ($product && $product->exists) {
            $this->productId = $product->id;
            $this->outletId = $product->outlet_id;
            $this->name = $product->name;
            $this->sku = (string) $product->sku;
            $this->barcode = (string) $product->barcode;
            $this->description = (string) $product->description;
            $this->price = (int) $product->price;
            $this->cost = (int) $product->cost;
            $this->track_stock = (bool) $product->track_stock;
            $this->low_stock_threshold = (int) $product->low_stock_threshold;
            $this->active = (bool) $product->active;
        } elseif ($outlet && $outlet->exists) {
            $this->outletId = $outlet->id;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'integer', 'min:0'],
            'cost' => ['required', 'integer', 'min:0'],
            'track_stock' => ['boolean'],
            'initial_stock' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'active' => ['boolean'],
        ];
    }

    public function save(RecordStockMovement $movements): void
    {
        $data = $this->validate();

        $tenant = Tenant::current();
        $isNew = $this->productId === null;
        $canManagePrices = $this->currentRole()->canManagePrices();

        // Belt-and-braces, the role.prices middleware already gates the
        // create route, but a clerk who deep-links or crafts a wire:call
        // shouldn't be able to slip a new product in.
        if ($isNew && ! $canManagePrices) {
            throw new AuthorizationException('Only managers can create products.');
        }

        $product = $isNew ? new Product : Product::query()->findOrFail($this->productId);

        $fillData = [
            'tenant_id' => $tenant?->id,
            'outlet_id' => $this->outletId,
            'name' => $data['name'],
            'sku' => $data['sku'] !== '' ? $data['sku'] : null,
            'barcode' => trim($data['barcode'] ?? '') !== '' ? trim($data['barcode']) : null,
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'track_stock' => (bool) $data['track_stock'],
            'low_stock_threshold' => (int) ($data['low_stock_threshold'] ?? 0),
            'active' => (bool) $data['active'],
        ];
        // Clerks can't edit price/cost, omit from fill so the model's
        // existing DB values survive the save().
        if ($canManagePrices) {
            $fillData['price'] = (int) $data['price'];
            $fillData['cost'] = (int) $data['cost'];
        }
        $product->fill($fillData);
        if ($isNew) {
            $product->quantity_on_hand = 0; // enforce zero pre-movement
        }
        $product->save();

        // Seed opening stock via a ledger movement so the audit trail
        // reflects it correctly (rather than a silent column set).
        if ($isNew && $data['track_stock'] && ((int) ($data['initial_stock'] ?? 0)) > 0) {
            $product->refresh();
            $movements->handle(
                product: $product,
                delta: (int) $data['initial_stock'],
                reason: StockMovementReason::InitialStock,
                operator: auth('web')->user(),
                unitCost: (int) $data['cost'] > 0 ? (int) $data['cost'] : null,
                note: 'Initial stock recorded at product creation',
            );
        }

        session()->flash('status', $isNew ? 'Product created.' : 'Product updated.');
        $this->redirectRoute('tenant.inventory.outlets.show', ['outlet' => $product->outlet_id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.dashboard.inventory.product-form', [
            'outlet' => $this->outletId ? Outlet::query()->find($this->outletId) : null,
            'canManagePrices' => $this->currentRole()->canManagePrices(),
        ]);
    }

    private function currentRole(): OperatorRole
    {
        $op = auth('web')->user();

        return ($op instanceof \App\Models\Operator ? $op->role : null) ?? OperatorRole::Manager;
    }
}
