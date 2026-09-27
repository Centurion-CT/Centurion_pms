<?php

declare(strict_types=1);

use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PurchaseOrderStatus;
use App\Livewire\Dashboard\Purchasing\PurchaseOrderShow;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'po-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->outlet->id,
        'name' => 'Guinness Small', 'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 5, 'low_stock_threshold' => 10,
    ]);
    $this->supplier = Supplier::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'BrewCo', 'contact_name' => 'Mo',
        'phone' => '08012345678', 'email' => 'sales@brew.test',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('creates a draft PO with subtotal + total computed from lines', function (): void {
    $po = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        outlet: $this->outlet,
        lines: [['product_id' => $this->product->id, 'quantity_ordered' => 24, 'unit_cost' => 800]],
        operator: $this->manager,
        notes: 'Weekly restock',
    );

    expect($po->status)->toBe(PurchaseOrderStatus::Draft);
    expect($po->reference)->toStartWith('KV-PO-');
    expect($po->subtotal)->toBe(24 * 800);
    expect($po->total)->toBe(24 * 800);
    expect($po->lines)->toHaveCount(1);
    expect($po->lines->first()->quantity_received)->toBe(0);
});

it('receiving against a PO writes stock movements + updates cost basis', function (): void {
    $po = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        outlet: $this->outlet,
        lines: [['product_id' => $this->product->id, 'quantity_ordered' => 24, 'unit_cost' => 850]],
        operator: $this->manager,
        initialStatus: PurchaseOrderStatus::Sent,
    );

    // Partial receipt: 10 of 24.
    app(ReceivePurchaseOrder::class)->handle(
        po: $po,
        receiptByLineId: [$po->lines->first()->id => 10],
        operator: $this->manager,
    );

    $this->product->refresh();
    expect($this->product->quantity_on_hand)->toBe(15); // 5 initial + 10 received
    expect($this->product->cost)->toBe(850);

    $po->refresh()->load('lines');
    expect($po->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
    expect($po->lines->first()->quantity_received)->toBe(10);

    $movements = StockMovement::query()->where('product_id', $this->product->id)->get();
    expect($movements)->toHaveCount(1);
    expect($movements->first()->delta)->toBe(10);
    expect($movements->first()->reason->value)->toBe('received');

    // Second receipt: remaining 14 → fully received.
    app(ReceivePurchaseOrder::class)->handle(
        po: $po,
        receiptByLineId: [$po->lines->first()->id => 14],
        operator: $this->manager,
    );

    $po->refresh()->load('lines');
    expect($po->status)->toBe(PurchaseOrderStatus::Received);
    expect($po->received_at)->not->toBeNull();
    expect($po->lines->first()->quantity_received)->toBe(24);
    expect($this->product->fresh()->quantity_on_hand)->toBe(29);
});

it('receiveAll shortcut settles all outstanding lines at once', function (): void {
    $po = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        outlet: $this->outlet,
        lines: [['product_id' => $this->product->id, 'quantity_ordered' => 6, 'unit_cost' => 700]],
        operator: $this->manager,
        initialStatus: PurchaseOrderStatus::Sent,
    );

    app(ReceivePurchaseOrder::class)->receiveAll($po, $this->manager);

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Received);
    expect($this->product->fresh()->quantity_on_hand)->toBe(11);
});

it('receiving more than outstanding throws', function (): void {
    $po = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        outlet: $this->outlet,
        lines: [['product_id' => $this->product->id, 'quantity_ordered' => 5, 'unit_cost' => 800]],
        operator: $this->manager,
        initialStatus: PurchaseOrderStatus::Sent,
    );

    expect(fn () => app(ReceivePurchaseOrder::class)->handle(
        po: $po,
        receiptByLineId: [$po->lines->first()->id => 99],
        operator: $this->manager,
    ))->toThrow(InvalidArgumentException::class);
});

it('cancelled or already-received POs reject further receipts', function (): void {
    $po = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier, outlet: $this->outlet,
        lines: [['product_id' => $this->product->id, 'quantity_ordered' => 1, 'unit_cost' => 500]],
        operator: $this->manager, initialStatus: PurchaseOrderStatus::Sent,
    );
    $po->update(['status' => PurchaseOrderStatus::Cancelled, 'cancelled_at' => now()]);

    expect(fn () => app(ReceivePurchaseOrder::class)->receiveAll($po, $this->manager))
        ->toThrow(LogicException::class);
});

it('managers can mark a draft PO as sent from the show page', function (): void {
    $po = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier, outlet: $this->outlet,
        lines: [['product_id' => $this->product->id, 'quantity_ordered' => 3, 'unit_cost' => 1000]],
        operator: $this->manager,
    );

    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(PurchaseOrderShow::class, ['order' => $po])
        ->call('markSent');

    $po->refresh();
    expect($po->status)->toBe(PurchaseOrderStatus::Sent);
    expect($po->placed_at)->not->toBeNull();
});

it('rejects mixing outlets and products', function (): void {
    $other = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Kitchen', 'type' => OutletType::Kitchen,
    ]);
    $kitchenProduct = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $other->id,
        'name' => 'Jollof', 'price' => 3000, 'track_stock' => false,
    ]);

    expect(fn () => app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        outlet: $this->outlet, // bar outlet, but line is for kitchen product
        lines: [['product_id' => $kitchenProduct->id, 'quantity_ordered' => 5, 'unit_cost' => 100]],
        operator: $this->manager,
    ))->toThrow(InvalidArgumentException::class);
});
