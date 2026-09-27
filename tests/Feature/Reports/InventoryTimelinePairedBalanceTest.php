<?php

declare(strict_types=1);

use App\Actions\Inventory\ApproveStockRequest;
use App\Actions\Inventory\CreateStockRequest;
use App\Actions\Inventory\SavePhysicalCount;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Reports\InventoryTimeline;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'timeline-paired']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->store = Store::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Main store',
    ]);
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Rooftop Bar',
        'type' => OutletType::Bar,
        'tax_rate' => 7.5,
        'active' => true,
    ]);
    $this->storeProduct = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'name' => 'Guinness Small 330ml',
        'price' => 1500,
        'cost' => 900,
        'track_stock' => true,
        'quantity_on_hand' => 100,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('surfaces balance_after + paired outlet balance for store-side Issued rows', function (): void {
    // Store keeper issues 24 units to the bar via the request pipeline
    // so the paired outlet-side StockMovement is written with quantity_after.
    $request = app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeProduct,
        outlet:       $this->outlet,
        quantity:     24,
        requestedBy:  $this->manager,
    );
    app(ApproveStockRequest::class)->handle(
        request:          $request,
        approvedBy:       $this->manager,
        approvedQuantity: 24,
    );

    // Bar physically counts 22 at end of day, 2 unaccounted for.
    $outletProduct = Product::query()
        ->where('outlet_id', $this->outlet->id)
        ->where('name', $this->storeProduct->name)
        ->firstOrFail();
    app(SavePhysicalCount::class)->handle(
        product:     $outletProduct,
        date:        Carbon::today(),
        physicalQty: 22,
        operator:    $this->manager,
    );

    $entries = app(InventoryTimeline::class)->build();

    $storeIssued = $entries->first(
        fn (array $e): bool => $e['source'] === 'stock'
            && $e['subject_id'] === $this->storeProduct->id
            && str_contains($e['summary'], 'Issued'),
    );

    expect($storeIssued)->not->toBeNull();
    // Running-balance column feed
    expect($storeIssued['balance_after'])->toBe(76); // 100 - 24
    // Summary no longer duplicates "(now N)"
    expect($storeIssued['summary'])->not->toContain('now');
    // Paired outlet chip fields
    expect($storeIssued['paired'])->toBeArray();
    expect($storeIssued['paired']['outlet_name'])->toBe('Rooftop Bar');
    expect($storeIssued['paired']['outlet_balance_after'])->toBe(24);
    expect($storeIssued['paired']['outlet_counted'])->toBe(22);
});

it('carries rotational bucket snapshot on stock rows for rotational products', function (): void {
    // Flip Guinness to rotational + seed in-use/at-laundry counts, then
    // fire a plain receipt so we get a stock row on the timeline.
    $this->storeProduct->update([
        'is_rotational'  => true,
        'qty_in_use'     => 7,
        'qty_at_laundry' => 3,
    ]);
    \App\Models\StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->storeProduct->id,
        'operator_id'    => $this->manager->id,
        'reason'         => \App\Enums\StockMovementReason::Received,
        'delta'          => 5,
        'quantity_after' => 105,
        'unit_cost'      => 900,
        'occurred_at'    => now(),
    ]);

    $entries = app(\App\Services\Reports\InventoryTimeline::class)->build();
    $received = $entries->first(
        fn (array $e): bool => $e['source'] === 'stock' && str_contains($e['summary'], 'Received'),
    );

    expect($received['rotational'])->toBeArray();
    expect($received['rotational']['in_use'])->toBe(7);
    expect($received['rotational']['at_laundry'])->toBe(3);
});

it('leaves rotational null on non-rotational products', function (): void {
    \App\Models\StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->storeProduct->id,
        'operator_id'    => $this->manager->id,
        'reason'         => \App\Enums\StockMovementReason::Received,
        'delta'          => 5,
        'quantity_after' => 105,
        'unit_cost'      => 900,
        'occurred_at'    => now(),
    ]);

    $entries = app(\App\Services\Reports\InventoryTimeline::class)->build();
    $received = $entries->first(
        fn (array $e): bool => $e['source'] === 'stock' && str_contains($e['summary'], 'Received'),
    );

    expect($received['rotational'])->toBeNull();
});

it('leaves paired null on receipts and other non-Issued stock rows', function (): void {
    // A plain +50 receipt (no source_id, not Issued).
    \App\Models\StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->storeProduct->id,
        'operator_id'    => $this->manager->id,
        'reason'         => \App\Enums\StockMovementReason::Received,
        'delta'          => 50,
        'quantity_after' => 150,
        'unit_cost'      => 900,
        'occurred_at'    => now(),
    ]);

    $entries = app(InventoryTimeline::class)->build();
    $received = $entries->first(
        fn (array $e): bool => $e['source'] === 'stock' && str_contains($e['summary'], 'Received'),
    );

    expect($received)->not->toBeNull();
    expect($received['balance_after'])->toBe(150);
    expect($received['paired'])->toBeNull();
});
