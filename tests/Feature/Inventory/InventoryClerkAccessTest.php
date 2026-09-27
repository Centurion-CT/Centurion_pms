<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Inventory\OutletForm;
use App\Livewire\Dashboard\Inventory\ProductForm;
use App\Livewire\Dashboard\Inventory\ReceiveStock;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'clerk-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar, 'tax_rate' => 7.5, 'active' => true,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->outlet->id,
        'name' => 'Guinness', 'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 20,
        'active' => true, 'low_stock_threshold' => 0,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->clerk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::InventoryClerk,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── Enum-level ─────────────────────────────────────────────────── */

it('InventoryClerk can access inventory module but not pos or staff', function (): void {
    expect(OperatorRole::InventoryClerk->canAccessModule('inventory'))->toBeTrue();
    expect(OperatorRole::InventoryClerk->canAccessModule('dashboard'))->toBeTrue();
    expect(OperatorRole::InventoryClerk->canAccessModule('pos'))->toBeFalse();
    expect(OperatorRole::InventoryClerk->canAccessModule('staff'))->toBeFalse();
    expect(OperatorRole::InventoryClerk->canAccessModule('finance'))->toBeFalse();
});

it('canManagePrices is Manager-only', function (): void {
    expect(OperatorRole::Manager->canManagePrices())->toBeTrue();
    expect(OperatorRole::InventoryClerk->canManagePrices())->toBeFalse();
    expect(OperatorRole::PosStaff->canManagePrices())->toBeFalse();
    expect(OperatorRole::Frontdesk->canManagePrices())->toBeFalse();
});

it('InventoryClerk home route lands on the inventory index', function (): void {
    expect(OperatorRole::InventoryClerk->homeRoute())->toBe('tenant.inventory.index');
});

/* ── ProductForm ─────────────────────────────────────────────────── */

it('clerk editing a product preserves the existing price and cost', function (): void {
    Livewire::actingAs($this->clerk, 'web')
        ->test(ProductForm::class, ['product' => $this->guinness])
        ->set('name', 'Guinness Extra')
        ->set('price', 9_999)   // clerk tries to slip a new price in
        ->set('cost', 8_888)
        ->call('save');

    $fresh = $this->guinness->fresh();
    expect($fresh->name)->toBe('Guinness Extra');       // non-price edit went through
    expect($fresh->price)->toBe(1_500);                  // untouched
    expect($fresh->cost)->toBe(700);                     // untouched
});

it('manager editing a product can change price and cost', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(ProductForm::class, ['product' => $this->guinness])
        ->set('price', 2_000)
        ->set('cost', 900)
        ->call('save');

    $fresh = $this->guinness->fresh();
    expect($fresh->price)->toBe(2_000);
    expect($fresh->cost)->toBe(900);
});

it('clerk creating a product is refused with AuthorizationException', function (): void {
    Livewire::actingAs($this->clerk, 'web')
        ->test(ProductForm::class, ['outlet' => $this->outlet])
        ->set('name', 'Sneaky Beer')
        ->set('price', 100)
        ->set('cost', 50)
        ->call('save')
        ->assertStatus(403);

    expect(Product::query()->where('name', 'Sneaky Beer')->exists())->toBeFalse();
});

/* ── OutletForm ─────────────────────────────────────────────────── */

it('clerk editing an outlet preserves the existing tax rate', function (): void {
    Livewire::actingAs($this->clerk, 'web')
        ->test(OutletForm::class, ['outlet' => $this->outlet])
        ->set('name', 'Rooftop Bar')
        ->set('tax_rate', '99.9')  // ignored server-side
        ->call('save');

    $fresh = $this->outlet->fresh();
    expect($fresh->name)->toBe('Rooftop Bar');
    expect((float) $fresh->tax_rate)->toBe(7.5);
});

it('clerk creating an outlet is refused with AuthorizationException', function (): void {
    Livewire::actingAs($this->clerk, 'web')
        ->test(OutletForm::class)
        ->set('name', 'Sneaky Kitchen')
        ->set('type', 'kitchen')
        ->set('tax_rate', '0')
        ->call('save')
        ->assertStatus(403);
});

/* ── ReceiveStock ─────────────────────────────────────────────────── */

it('clerk receiving stock adds quantity but leaves cost untouched', function (): void {
    Livewire::actingAs($this->clerk, 'web')
        ->test(ReceiveStock::class, ['outlet' => $this->outlet])
        ->set('lines.0.product_id', $this->guinness->id)
        ->set('lines.0.quantity', 5)
        ->set('lines.0.unit_cost', 9_999)  // server strips this for clerks
        ->call('save');

    $fresh = $this->guinness->fresh();
    expect($fresh->quantity_on_hand)->toBe(25);
    expect($fresh->cost)->toBe(700);       // unchanged
});

it('manager receiving stock with unit_cost updates the product cost', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(ReceiveStock::class, ['outlet' => $this->outlet])
        ->set('lines.0.product_id', $this->guinness->id)
        ->set('lines.0.quantity', 5)
        ->set('lines.0.unit_cost', 800)
        ->call('save');

    expect($this->guinness->fresh()->cost)->toBe(800);
});

/* ── role.prices route middleware ─────────────────────────────────── */

it('clerk hitting the outlet-create URL is redirected by role.prices', function (): void {
    $this->actingAs($this->clerk, 'web');
    $this->get('/dashboard/inventory/outlets/new')->assertRedirect();
});

it('clerk hitting the product-create URL is redirected by role.prices', function (): void {
    $this->actingAs($this->clerk, 'web');
    $this->get("/dashboard/inventory/outlets/{$this->outlet->id}/products/new")->assertRedirect();
});

it('manager hitting the outlet-create URL passes role.prices', function (): void {
    $this->actingAs($this->manager, 'web');
    $this->get('/dashboard/inventory/outlets/new')->assertOk();
});
