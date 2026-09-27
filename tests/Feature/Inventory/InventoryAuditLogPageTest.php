<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockMovementReason;
use App\Livewire\Dashboard\Inventory\AuditLog;
use App\Livewire\Dashboard\Inventory\ProductForm;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'audit-page']);
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

    // Seed one activity + one stock movement so the page has data.
    Livewire::actingAs($this->manager, 'web')
        ->test(ProductForm::class, ['product' => $this->guinness])
        ->set('price', 2_000)
        ->call('save');

    StockMovement::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->guinness->id,
        'operator_id' => $this->manager->id,
        'reason' => StockMovementReason::Received,
        'delta' => 10,
        'quantity_after' => 30,
        'unit_cost' => 700,
        'note' => 'Test delivery',
        'occurred_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('merges Spatie activity + stock movements into one timeline', function (): void {
    // Running balance ("now N") moved from the Change summary into its
    // own Balance column, so the assertion is split accordingly.
    Livewire::actingAs($this->manager, 'web')
        ->test(AuditLog::class)
        ->assertSee('Guinness')
        ->assertSee('Price ₦1,500 → ₦2,000')     // from Spatie activity
        ->assertSee('+10 · Received')             // from stock movement
        ->assertSeeHtml('data-testid="audit-balance"');
});

it('clerk can view the audit page', function (): void {
    $this->actingAs($this->clerk, 'web');
    $this->get('/dashboard/inventory/audit-log')->assertOk();
});

it('filters by kind — Stock chip narrows to stock rows only', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(AuditLog::class)
        ->set('kindFilter', ['stock'])
        ->assertSee('+10 · Received')
        ->assertDontSee('Price ₦1,500 → ₦2,000');
});

it('filters by kind — Price chip narrows to price rows only', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(AuditLog::class)
        ->set('kindFilter', ['price'])
        ->assertSee('Price ₦1,500 → ₦2,000')
        ->assertDontSee('+10 · Received');
});

it('filters by operator narrows to that operator only', function (): void {
    $other = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Other Mgr']);

    Livewire::actingAs($this->manager, 'web')
        ->test(AuditLog::class)
        ->set('operatorFilter', (string) $other->id)
        ->assertDontSee('Price ₦1,500 → ₦2,000');
});

it('filters by product narrows to that product only', function (): void {
    $other = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->outlet->id,
        'name' => 'Other Beer', 'price' => 900, 'cost' => 400,
        'track_stock' => true, 'quantity_on_hand' => 5,
    ]);
    // Log an activity on the other product too.
    Livewire::actingAs($this->manager, 'web')
        ->test(ProductForm::class, ['product' => $other])
        ->set('price', 1_000)
        ->call('save');

    Livewire::actingAs($this->manager, 'web')
        ->test(AuditLog::class)
        ->set('productFilter', (string) $this->guinness->id)
        ->assertSee('Price ₦1,500 → ₦2,000')
        ->assertDontSee('Price ₦900 → ₦1,000');
});

it('clearFilters resets all filter state', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(AuditLog::class)
        ->set('kindFilter', ['stock'])
        ->set('productFilter', '5')
        ->set('operatorFilter', '3')
        ->set('fromDate', '2026-01-01')
        ->set('toDate', '2026-12-31')
        ->call('clearFilters')
        ->assertSet('kindFilter', [])
        ->assertSet('productFilter', '')
        ->assertSet('operatorFilter', '')
        ->assertSet('fromDate', '')
        ->assertSet('toDate', '');
});
