<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockModel;
use App\Livewire\Dashboard\Inventory\OutletShow;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'stock-gate-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Kitchen',
        'type' => OutletType::Kitchen,
        'tax_rate' => 0,
        'active' => true,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'activated_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── Route middleware ───────────────────────────────────────────── */

it('serves /dashboard/store when property runs on store_and_requisition', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::StoreAndRequisition])->save();

    $this->actingAs($this->manager, 'web');
    $this->get('http://stock-gate-test.keverna.test/dashboard/store')->assertOk();
});

it('redirects /dashboard/store to /store/off when property runs on per_department', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();

    $this->actingAs($this->manager, 'web');
    $this->get('http://stock-gate-test.keverna.test/dashboard/store')
        ->assertRedirect('http://stock-gate-test.keverna.test/dashboard/store/off');
});

it('redirects every store child route to /store/off when per_department', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();
    $this->actingAs($this->manager, 'web');

    foreach ([
        '/dashboard/store',
        '/dashboard/store/issue',
        '/dashboard/store/receive',
        '/dashboard/store/products',
        '/dashboard/store/products/new',
    ] as $path) {
        $this->get('http://stock-gate-test.keverna.test'.$path)
            ->assertRedirect('http://stock-gate-test.keverna.test/dashboard/store/off');
    }
});

it('redirects purchasing routes to /store/off when per_department (they assume central-store receiving)', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();
    $this->actingAs($this->manager, 'web');

    $this->get('http://stock-gate-test.keverna.test/dashboard/purchasing/orders')
        ->assertRedirect('http://stock-gate-test.keverna.test/dashboard/store/off');
    $this->get('http://stock-gate-test.keverna.test/dashboard/purchasing/suppliers')
        ->assertRedirect('http://stock-gate-test.keverna.test/dashboard/store/off');
});

it('serves /dashboard/store/off with a link back to property settings', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();

    $this->actingAs($this->manager, 'web');
    $this->get('http://stock-gate-test.keverna.test/dashboard/store/off')
        ->assertOk()
        ->assertSee('Central store isn&rsquo;t turned on', false)
        ->assertSee('data-testid="store-off-open-settings"', false);
});

/* ── OutletShow branch ──────────────────────────────────────────── */

it('OutletShow shows "Request from store" button in store_and_requisition mode', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::StoreAndRequisition])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(OutletShow::class, ['outlet' => $this->outlet])
        ->assertSet('outletId', $this->outlet->id)
        ->assertSeeHtml('data-testid="request-from-store-btn"')
        ->assertDontSeeHtml('data-testid="receive-stock-direct-btn"');
});

it('OutletShow shows "Receive stock" button in per_department mode instead', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(OutletShow::class, ['outlet' => $this->outlet])
        ->assertSeeHtml('data-testid="receive-stock-direct-btn"')
        ->assertDontSeeHtml('data-testid="request-from-store-btn"');
});

it('OutletShow shows "Add product" button in per_department mode so the outlet can seed its catalog', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(OutletShow::class, ['outlet' => $this->outlet])
        ->assertSeeHtml('data-testid="add-product-direct-btn"');
});

it('OutletShow hides "Add product" button in store_and_requisition mode (catalog comes from the store)', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::StoreAndRequisition])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(OutletShow::class, ['outlet' => $this->outlet])
        ->assertDontSeeHtml('data-testid="add-product-direct-btn"');
});

it('OutletShow submitRequest is a no-op in per_department mode', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(OutletShow::class, ['outlet' => $this->outlet])
        ->set('requestStoreProductId', 999)
        ->set('requestQuantity', 5)
        ->call('submitRequest')
        ->assertHasErrors('requestQuantity');
});

it('OutletShow openRequest does not open the modal in per_department mode', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(OutletShow::class, ['outlet' => $this->outlet])
        ->call('openRequest', 42)
        ->assertSet('requestModalOpen', false);
});

it('OutletShow openRequest opens the modal in store_and_requisition mode', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::StoreAndRequisition])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(OutletShow::class, ['outlet' => $this->outlet])
        ->call('openRequest', 42)
        ->assertSet('requestModalOpen', true)
        ->assertSet('requestStoreProductId', 42);
});
