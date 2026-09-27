<?php

declare(strict_types=1);

use App\Actions\Inventory\ApproveStockRequest;
use App\Actions\Inventory\CreateStockRequest;
use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PosSaleStatus;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Inventory\StoreReconciliation;
use Illuminate\Support\Carbon;

/**
 * Property-manager scenario: over a week, receive stock into the
 * store, issue to outlets, ring some POS sales, then confirm the
 * reconciliation shows the flows + variance correctly.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'recon']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();

    $this->store = Store::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Main store',
    ]);

    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Bar',
        'type' => OutletType::Bar,
    ]);
    $this->kitchen = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Kitchen',
        'type' => OutletType::Kitchen,
    ]);

    $this->beer = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'name' => 'Beer',
        'sku' => 'BEER',
        'price' => 1500,
        'cost' => 800,
        'track_stock' => true,
        'quantity_on_hand' => 0,
    ]);

    $this->keeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::StockKeeper]);
    $this->clerk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::InventoryClerk]);

    Carbon::setTestNow('2027-05-10 09:00:00');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('shows receipts + issuances + POS sales for the window', function (): void {
    // Receive 20 units of beer @ ₦800 each = ₦16,000.
    StockMovement::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->beer->id,
        'operator_id' => $this->keeper->id,
        'reason' => StockMovementReason::Received->value,
        'delta' => 20,
        'quantity_after' => 20,
        'unit_cost' => 800,
        'occurred_at' => Carbon::now(),
    ]);
    $this->beer->update(['quantity_on_hand' => 20]);

    // Bar requests 12, keeper approves — 12 issued at cost ₦800.
    $req = app(CreateStockRequest::class)->handle(
        storeProduct: $this->beer, outlet: $this->bar,
        quantity: 12, requestedBy: $this->clerk,
    );
    app(ApproveStockRequest::class)->handle($req, $this->keeper, 12);

    // Kitchen requests 4, approved.
    $req2 = app(CreateStockRequest::class)->handle(
        storeProduct: $this->beer, outlet: $this->kitchen,
        quantity: 4, requestedBy: $this->clerk,
    );
    app(ApproveStockRequest::class)->handle($req2, $this->keeper, 4);

    // Bar sells 5 via POS.
    $barBeer = Product::query()->where('outlet_id', $this->bar->id)->where('sku', 'BEER')->firstOrFail();
    $sale = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar,
        operator: $this->keeper,
        cart: [['product_id' => $barBeer->id, 'quantity' => 5]],
    );
    app(CompletePosSale::class)->handle($sale, $this->keeper, 'cash');

    $data = app(StoreReconciliation::class)->build(
        $this->store,
        Carbon::today()->subDays(3),
        Carbon::today(),
    );

    // Received: 20 units @ ₦800 = ₦16,000.
    expect((int) $data['totals']['received']['qty'])->toBe(20);
    expect((int) $data['totals']['received']['value'])->toBe(16_000);

    // Issued: 12 + 4 = 16 units @ ₦800 = ₦12,800.
    expect((int) $data['totals']['issued']['qty'])->toBe(16);
    expect((int) $data['totals']['issued']['value'])->toBe(12_800);

    // Sold: 5 units × ₦1,500 = ₦7,500.
    expect((int) $data['totals']['sold']['qty'])->toBe(5);
    expect((int) $data['totals']['sold']['value'])->toBe(7_500);

    // Per-outlet breakdown on the single beer row.
    $row = $data['rows'][0];
    expect((int) $row['issued']['per_outlet'][$this->bar->id]['qty'])->toBe(12);
    expect((int) $row['issued']['per_outlet'][$this->kitchen->id]['qty'])->toBe(4);
    expect((int) $row['sold']['per_outlet'][$this->bar->id]['qty'])->toBe(5);
    expect((int) $row['sold']['per_outlet'][$this->kitchen->id]['qty'])->toBe(0);

    // Store expected closing = 0 opening + 20 received - 16 issued = 4.
    expect((int) $row['expected_closing'])->toBe(4);
});

it('excludes movements outside the window', function (): void {
    // Old receipt 30 days ago — should not appear in a today-only window.
    StockMovement::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->beer->id,
        'operator_id' => $this->keeper->id,
        'reason' => StockMovementReason::Received->value,
        'delta' => 100,
        'quantity_after' => 100,
        'unit_cost' => 800,
        'occurred_at' => Carbon::now()->subDays(30),
    ]);

    $data = app(StoreReconciliation::class)->build(
        $this->store,
        Carbon::today(),
        Carbon::today(),
    );

    expect((int) $data['totals']['received']['qty'])->toBe(0);
    // Opening carries the 100 forward though.
    expect((int) $data['rows'][0]['opening'])->toBe(100);
});

it('returns an empty result when the store has no products', function (): void {
    $emptyStore = Store::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => Property::factory()->for($this->tenant)->create()->id,
        'name' => 'Other store',
    ]);

    $data = app(StoreReconciliation::class)->build($emptyStore, Carbon::today(), Carbon::today());
    expect($data['rows'])->toBe([]);
    expect((int) $data['totals']['received']['value'])->toBe(0);
});
