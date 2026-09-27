<?php

declare(strict_types=1);

use App\Actions\Inventory\RecordStockIssuance;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'issue-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();

    $this->store = Store::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Main store',
    ]);
    $this->outlet = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Bar',
        'type'        => OutletType::Bar,
    ]);

    $this->storeProduct = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'store_id'         => $this->store->id,
        'name'             => 'Guinness',
        'price'            => 0,
        'cost'             => 850_00,
        'track_stock'      => true,
        'quantity_on_hand' => 30,
    ]);
    $this->outletProduct = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'outlet_id'        => $this->outlet->id,
        'name'             => 'Guinness',
        'price'            => 1_200_00,
        'cost'             => 850_00,
        'track_stock'      => true,
        'quantity_on_hand' => 0,
    ]);
    $this->keeper = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::StockKeeper,
    ]);
});

it('writes paired -N / +N ledger rows, updates both quantities, and cross-links source', function (): void {
    $pair = app(RecordStockIssuance::class)->handle(
        storeProduct:  $this->storeProduct,
        outletProduct: $this->outletProduct,
        quantity:      12,
        operator:      $this->keeper,
        note:          'Weekend restock',
    );

    // Store side
    expect($this->storeProduct->fresh()->quantity_on_hand)->toBe(18);
    expect($pair['store']->reason)->toBe(StockMovementReason::Issued);
    expect($pair['store']->delta)->toBe(-12);
    expect($pair['store']->quantity_after)->toBe(18);
    expect($pair['store']->operator_id)->toBe($this->keeper->id);
    expect($pair['store']->note)->toBe('Weekend restock');

    // Outlet side
    expect($this->outletProduct->fresh()->quantity_on_hand)->toBe(12);
    expect($pair['outlet']->reason)->toBe(StockMovementReason::Issued);
    expect($pair['outlet']->delta)->toBe(12);
    expect($pair['outlet']->quantity_after)->toBe(12);

    // Cross-linked source_type / source_id
    expect($pair['store']->source_type)->toBe(StockMovement::class);
    expect($pair['store']->source_id)->toBe($pair['outlet']->id);
    expect($pair['outlet']->source_type)->toBe(StockMovement::class);
    expect($pair['outlet']->source_id)->toBe($pair['store']->id);
});

it('refuses to issue more than the store has on hand', function (): void {
    app(RecordStockIssuance::class)->handle(
        storeProduct:  $this->storeProduct,
        outletProduct: $this->outletProduct,
        quantity:      31,   // store has 30
    );
})->throws(RuntimeException::class, 'Not enough stock');

it('refuses when the source product is an outlet product (not a store product)', function (): void {
    $barProduct2 = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'outlet_id'        => $this->outlet->id,
        'name'             => 'Not-a-store-product',
        'price'            => 100,
        'cost'             => 50,
        'track_stock'      => true,
        'quantity_on_hand' => 100,
    ]);

    app(RecordStockIssuance::class)->handle(
        storeProduct:  $barProduct2,
        outletProduct: $this->outletProduct,
        quantity:      5,
    );
})->throws(RuntimeException::class, 'not a store product');

it('refuses a non-positive quantity', function (): void {
    app(RecordStockIssuance::class)->handle(
        storeProduct:  $this->storeProduct,
        outletProduct: $this->outletProduct,
        quantity:      0,
    );
})->throws(RuntimeException::class, 'must be positive');
