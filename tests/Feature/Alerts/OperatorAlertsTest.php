<?php

declare(strict_types=1);

use App\Actions\Inventory\CreateStockRequest;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Alerts\OperatorAlerts;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'alerts-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->store = Store::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Main store',
    ]);
    $this->bar = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Rooftop Bar',
        'type'        => OutletType::Bar,
        'tax_rate'    => 7.5,
        'active'      => true,
    ]);
    $this->housekeeping = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Guest Amenities',
        'type'        => OutletType::Housekeeping,
        'tax_rate'    => 0,
        'active'      => true,
    ]);

    // Store product to fund a requisition.
    $this->storeGuinness = Product::query()->create([
        'tenant_id'          => $this->tenant->id,
        'store_id'           => $this->store->id,
        'name'               => 'Guinness',
        'price'              => 1500, 'cost' => 900,
        'track_stock'        => true, 'quantity_on_hand' => 40, 'low_stock_threshold' => 5,
    ]);

    // Low-stock bar SKU: on_hand <= threshold, threshold > 0.
    $this->barGuinness = Product::query()->create([
        'tenant_id'          => $this->tenant->id,
        'outlet_id'          => $this->bar->id,
        'name'               => 'Guinness Small 330ml',
        'price'              => 1500, 'cost' => 900,
        'track_stock'        => true, 'quantity_on_hand' => 3, 'low_stock_threshold' => 6,
    ]);

    // Low-stock housekeeping SKU.
    $this->bedsheet = Product::query()->create([
        'tenant_id'          => $this->tenant->id,
        'outlet_id'          => $this->housekeeping->id,
        'name'               => 'Bedsheet',
        'price'              => 4500, 'cost' => 3000,
        'track_stock'        => true, 'quantity_on_hand' => 2, 'low_stock_threshold' => 6,
    ]);

    // Pending stock request from bar → store.
    app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeGuinness,
        outlet:       $this->bar,
        quantity:     12,
    );
});

afterEach(fn () => Tenant::forgetCurrent());

it('routes store requests only to Manager + StockKeeper', function (): void {
    $svc = app(OperatorAlerts::class);

    foreach ([OperatorRole::Manager, OperatorRole::StockKeeper] as $role) {
        $op = Operator::factory()->for($this->tenant)->create(['role' => $role]);
        expect($svc->counts($op)['store_request'])->toBe(1);
    }

    foreach ([
        OperatorRole::Frontdesk,
        OperatorRole::HouseCleaner,
        OperatorRole::Porter,
        OperatorRole::Supervisor,
        OperatorRole::InventoryClerk,
        OperatorRole::ReservationOfficer,
        OperatorRole::PosStaff,
    ] as $role) {
        $op = Operator::factory()->for($this->tenant)->create(['role' => $role]);
        expect($svc->counts($op)['store_request'])->toBe(0);
    }
});

it('fans low-stock housekeeping items out to cleaners + front desk + supervisors', function (): void {
    $svc = app(OperatorAlerts::class);

    // Manager sees BOTH the bar and housekeeping low-stock items.
    $manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    expect($svc->counts($manager)['low_stock'])->toBe(2);

    // House cleaner sees ONLY the housekeeping SKU (Bedsheet), not the bar's Guinness.
    $cleaner = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $items = $svc->items($cleaner)->where('kind', 'low_stock');
    expect($svc->counts($cleaner)['low_stock'])->toBe(1);
    expect($items->first()['title'])->toBe('Bedsheet');

    // Front desk sees the housekeeping SKU only.
    $frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);
    expect($svc->counts($frontdesk)['low_stock'])->toBe(1);

    // Inventory clerk is an inventory admin for the whole property, so
    // sees both housekeeping and non-housekeeping outlets.
    $clerk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::InventoryClerk]);
    expect($svc->counts($clerk)['low_stock'])->toBe(2);

    // PosStaff / ReservationOfficer see no low-stock alerts at all.
    foreach ([OperatorRole::PosStaff, OperatorRole::ReservationOfficer] as $role) {
        $op = Operator::factory()->for($this->tenant)->create(['role' => $role]);
        expect($svc->counts($op)['low_stock'])->toBe(0);
    }
});

it('links housekeeping low-stock items at the Supplies page and other outlets at Inventory', function (): void {
    $manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $items = app(OperatorAlerts::class)->items($manager)->where('kind', 'low_stock');

    $bedsheet = $items->firstWhere('title', 'Bedsheet');
    $barGuinness = $items->firstWhere('title', 'Guinness Small 330ml');

    expect($bedsheet['url'])->toContain('/dashboard/supplies/');
    expect($barGuinness['url'])->toContain('/dashboard/inventory/outlets/');
});

it('ignores products with threshold 0 (opt-out signal)', function (): void {
    $this->bedsheet->update(['low_stock_threshold' => 0]);
    $this->barGuinness->update(['low_stock_threshold' => 0]);

    $manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    expect(app(OperatorAlerts::class)->counts($manager)['low_stock'])->toBe(0);
});
