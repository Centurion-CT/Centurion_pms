<?php

declare(strict_types=1);

use App\Actions\Pos\ReceiveStock;
use App\Actions\Pos\RecordStockMovement;
use App\Enums\OutletType;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pos-stock']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Bar',
        'type'        => OutletType::Bar,
    ]);
    $this->product = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'outlet_id'        => $this->outlet->id,
        'name'             => 'Guinness Small',
        'price'            => 1500,
        'cost'             => 800,
        'track_stock'      => true,
        'quantity_on_hand' => 0,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('records a movement and updates products.quantity_on_hand atomically', function (): void {
    app(RecordStockMovement::class)->handle(
        product: $this->product,
        delta:   12,
        reason:  StockMovementReason::Received,
        operator: $this->operator,
        unitCost: 850,
    );

    $fresh = $this->product->fresh();
    expect($fresh->quantity_on_hand)->toBe(12);
    expect($fresh->stockMovements()->count())->toBe(1);

    $movement = $fresh->stockMovements()->first();
    expect($movement->delta)->toBe(12);
    expect($movement->quantity_after)->toBe(12);
    expect($movement->reason)->toBe(StockMovementReason::Received);
});

it('rejects a zero delta', function (): void {
    expect(fn () => app(RecordStockMovement::class)->handle(
        product: $this->product, delta: 0, reason: StockMovementReason::Adjusted,
    ))->toThrow(RuntimeException::class, 'cannot be zero');
});

it('rejects a movement on a service (non-tracked) product', function (): void {
    $this->product->forceFill(['track_stock' => false])->save();

    expect(fn () => app(RecordStockMovement::class)->handle(
        product: $this->product->fresh(), delta: 5, reason: StockMovementReason::Received,
    ))->toThrow(RuntimeException::class, 'track_stock is off');
});

it('ReceiveStock action writes one movement per line and updates cost', function (): void {
    $p2 = Product::query()->create([
        'tenant_id'   => $this->tenant->id,
        'outlet_id'   => $this->outlet->id,
        'name'        => 'Star Small',
        'price'       => 1200,
        'cost'        => 600,
        'track_stock' => true,
    ]);

    $count = app(ReceiveStock::class)->handle(
        lines: [
            ['product_id' => $this->product->id, 'quantity' => 24, 'unit_cost' => 900],
            ['product_id' => $p2->id, 'quantity' => 6, 'unit_cost' => null],
        ],
        operator: $this->operator,
        note: 'ABC Supplies · Inv 4421',
    );

    expect($count)->toBe(2);
    expect($this->product->fresh()->quantity_on_hand)->toBe(24);
    expect($this->product->fresh()->cost)->toBe(900);   // cost basis updated
    expect($p2->fresh()->quantity_on_hand)->toBe(6);
    expect($p2->fresh()->cost)->toBe(600);              // untouched
});
