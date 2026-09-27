<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Inventory\ProductForm;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'barcode-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small', 'sku' => 'GUIN-SM',
        'barcode' => '5000213024300',
        'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 20,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ─── Terminal scanning ──────────────────────────────────────────── */

// Barcode-to-product resolution now happens in the Alpine.js cart store
// (resources/js/pos-cart.js) against the IndexedDB cache, so the phone
// can scan while offline. The server-side path is /dashboard/pos/{outlet}/
// products.json, whose barcode/sku shape is covered by PosProductsJsonTest.

it('surfaces the barcode + sku on the products.json feed the scanner reads', function (): void {
    $this->actingAs($this->operator, 'web')
        ->getJson("/dashboard/pos/{$this->bar->id}/products.json")
        ->assertOk()
        ->assertJsonPath('products.0.barcode', '5000213024300')
        ->assertJsonPath('products.0.sku', 'GUIN-SM');
});

it('will not scan a product from a different outlet', function (): void {
    $kitchen = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Kitchen', 'type' => OutletType::Kitchen,
    ]);
    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $kitchen->id,
        'name' => 'Wine',
        'barcode' => '5000213024300', // conflict-free within tenant — force separate code
        'price' => 3000, 'track_stock' => true, 'quantity_on_hand' => 10,
    ]);
})->throws(QueryException::class); // guarded by (tenant_id, barcode) unique index

/* ─── ProductForm barcode field ──────────────────────────────────── */

it('saves barcode when a product is created', function (): void {
    Livewire\Livewire::actingAs($this->operator, 'web')
        ->test(ProductForm::class, ['outlet' => $this->bar])
        ->set('name', 'Star')
        ->set('barcode', '5060041520021')
        ->set('price', 1200)
        ->set('cost', 600)
        ->set('track_stock', true)
        ->set('initial_stock', 24)
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::query()->where('barcode', '5060041520021')->exists())->toBeTrue();
});

it('lets you edit a product barcode', function (): void {
    Livewire\Livewire::actingAs($this->operator, 'web')
        ->test(ProductForm::class, ['product' => $this->guinness])
        ->set('barcode', '9999888877776')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->guinness->fresh()->barcode)->toBe('9999888877776');
});

it('clearing the barcode input stores NULL rather than an empty string', function (): void {
    Livewire\Livewire::actingAs($this->operator, 'web')
        ->test(ProductForm::class, ['product' => $this->guinness])
        ->set('barcode', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->guinness->fresh()->barcode)->toBeNull();
});
