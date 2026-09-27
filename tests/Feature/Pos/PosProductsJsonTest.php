<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'products-json']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->kitchen = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Kitchen', 'type' => OutletType::Kitchen,
    ]);
    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness', 'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 20, 'sku' => 'GUIN', 'barcode' => '5000155601014',
    ]);
    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Retired Item', 'price' => 500, 'cost' => 200,
        'track_stock' => false, 'active' => false,
    ]);
    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->kitchen->id,
        'name' => 'Jollof', 'price' => 3500, 'cost' => 1200, 'track_stock' => false,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->posStaff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('returns only active products for the requested outlet', function (): void {
    $this->actingAs($this->manager, 'web');

    $response = $this->getJson("/dashboard/pos/{$this->bar->id}/products.json");

    $response->assertOk()
        ->assertJsonPath('outlet.id', $this->bar->id)
        ->assertJsonPath('outlet.name', 'Bar')
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.name', 'Guinness')
        ->assertJsonPath('products.0.barcode', '5000155601014')
        ->assertJsonPath('products.0.quantity_on_hand', 20);
});

it('PosStaff can fetch products for an assigned outlet', function (): void {
    $this->posStaff->outlets()->attach($this->bar->id);
    $this->actingAs($this->posStaff, 'web');

    $this->getJson("/dashboard/pos/{$this->bar->id}/products.json")
        ->assertOk()
        ->assertJsonCount(1, 'products');
});

it('PosStaff cannot fetch products for an outlet they are not assigned to', function (): void {
    $this->actingAs($this->posStaff, 'web');

    $this->getJson("/dashboard/pos/{$this->kitchen->id}/products.json")
        ->assertNotFound();
});

it('unauthenticated JSON requests are rejected with 401', function (): void {
    $this->getJson("/dashboard/pos/{$this->bar->id}/products.json")
        ->assertStatus(401);
});
