<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\StockMovementReason;
use App\Livewire\Dashboard\Stores\ProductForm as StoreProductForm;
use App\Livewire\Dashboard\Stores\Products as StoreProducts;
use App\Models\Operator;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'store-catalog-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->store = Store::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Main store',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->clerk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::InventoryClerk,
    ]);
});

it('manager can create a store product with initial stock — ledger row written', function (): void {
    $this->actingAs($this->manager, 'web');

    Livewire::test(StoreProductForm::class)
        ->set('name', 'Guinness small')
        ->set('sku', 'GUIN-330')
        ->set('price', 1_200_00)
        ->set('cost', 850_00)
        ->set('initial_stock', 24)
        ->set('low_stock_threshold', 6)
        ->call('save');

    $product = Product::query()
        ->where('store_id', $this->store->id)
        ->where('name', 'Guinness small')
        ->firstOrFail();
    expect($product->outlet_id)->toBeNull();
    expect($product->price)->toBe(1_200_00);
    expect($product->cost)->toBe(850_00);
    expect($product->quantity_on_hand)->toBe(24);
    expect($product->track_stock)->toBeTrue();

    // InitialStock marker in the ledger.
    $movement = StockMovement::query()->where('product_id', $product->id)->firstOrFail();
    expect($movement->reason)->toBe(StockMovementReason::InitialStock);
    expect($movement->delta)->toBe(24);
    expect($movement->quantity_after)->toBe(24);
});

it('manager can edit a store product and updates persist', function (): void {
    $this->actingAs($this->manager, 'web');

    $product = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'name' => 'Old name', 'price' => 100_00, 'cost' => 50_00,
        'track_stock' => true, 'quantity_on_hand' => 5,
    ]);

    Livewire::test(StoreProductForm::class, ['product' => $product])
        ->assertSet('name', 'Old name')
        ->set('name', 'Fresh name')
        ->set('price', 200_00)
        ->call('save');

    $updated = $product->fresh();
    expect($updated->name)->toBe('Fresh name');
    expect($updated->price)->toBe(200_00);
    // Qty untouched — form doesn't fiddle with quantity_on_hand on edit.
    expect($updated->quantity_on_hand)->toBe(5);
});

it('archive toggle flips active and restores again', function (): void {
    $this->actingAs($this->manager, 'web');

    $product = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'name' => 'Star', 'price' => 500_00, 'cost' => 200_00,
        'track_stock' => true, 'quantity_on_hand' => 12, 'active' => true,
    ]);

    Livewire::test(StoreProducts::class)
        ->call('toggleActive', $product->id);
    expect($product->fresh()->active)->toBeFalse();

    Livewire::test(StoreProducts::class)
        ->call('toggleActive', $product->id);
    expect($product->fresh()->active)->toBeTrue();
});

it('archived products are hidden by default and shown on toggle', function (): void {
    $this->actingAs($this->manager, 'web');

    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'name' => 'Alive', 'price' => 100, 'cost' => 50,
        'track_stock' => true, 'quantity_on_hand' => 0, 'active' => true,
    ]);
    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'name' => 'Dead', 'price' => 100, 'cost' => 50,
        'track_stock' => true, 'quantity_on_hand' => 0, 'active' => false,
    ]);

    Livewire::test(StoreProducts::class)
        ->assertSee('Alive')
        ->assertDontSee('Dead')
        ->call('toggleArchived')
        ->assertSee('Alive')
        ->assertSee('Dead');
});

it('clerk (canManagePrices=false) cannot slip in a new store product via wire:call', function (): void {
    $this->actingAs($this->clerk, 'web');

    // Livewire converts AuthorizationException to a 403 response so
    // the component doesn't bubble it out of ->call() — assert the
    // response status + confirm nothing landed in the DB.
    Livewire::test(StoreProductForm::class)
        ->set('name', 'Sneaky')
        ->set('price', 100)
        ->set('cost', 50)
        ->call('save')
        ->assertForbidden();

    expect(Product::query()->where('store_id', $this->store->id)->count())->toBe(0);
});
