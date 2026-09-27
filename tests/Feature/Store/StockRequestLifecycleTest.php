<?php

declare(strict_types=1);

use App\Actions\Inventory\ApproveStockRequest;
use App\Actions\Inventory\CancelStockRequest;
use App\Actions\Inventory\CreateStockRequest;
use App\Actions\Inventory\RejectStockRequest;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockMovementReason;
use App\Enums\StockRequestStatus;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'request-test']);
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

    $this->storeMalt = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'store_id'         => $this->store->id,
        'name'             => 'Malt',
        'sku'              => 'MALT-STR',
        'price'            => 500_00,     // default sell price for outlets that receive this
        'cost'             => 200_00,
        'track_stock'      => true,
        'quantity_on_hand' => 40,
    ]);

    $this->outletOp = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::InventoryClerk,
    ]);
    $this->keeper = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::StockKeeper,
    ]);
});

it('creates a pending request tied to the store product + target outlet', function (): void {
    $req = app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt,
        outlet:       $this->outlet,
        quantity:     12,
        requestedBy:  $this->outletOp,
        note:         'Weekend anticipated demand',
    );

    expect($req->status)->toBe(StockRequestStatus::Pending);
    expect($req->outlet_id)->toBe($this->outlet->id);
    expect($req->product_id)->toBe($this->storeMalt->id);
    expect($req->quantity)->toBe(12);
    expect($req->requested_by_operator_id)->toBe($this->outletOp->id);
    expect($req->requested_note)->toBe('Weekend anticipated demand');
    expect($req->resolved_at)->toBeNull();
});

it('rejects requests against a non-store product', function (): void {
    $outletMalt = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->outlet->id,
        'name' => 'X', 'price' => 100, 'cost' => 50, 'track_stock' => true, 'quantity_on_hand' => 0,
    ]);

    app(CreateStockRequest::class)->handle(
        storeProduct: $outletMalt,
        outlet:       $this->outlet,
        quantity:     3,
    );
})->throws(RuntimeException::class, 'not a store product');

it('approving a request auto-provisions the outlet product if it does not exist', function (): void {
    // Bar has no "Malt" yet.
    expect(Product::query()->where('outlet_id', $this->outlet->id)->count())->toBe(0);

    $req = app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt,
        outlet:       $this->outlet,
        quantity:     12,
        requestedBy:  $this->outletOp,
    );

    $approved = app(ApproveStockRequest::class)->handle(
        request:    $req,
        approvedBy: $this->keeper,
    );

    // Outlet product auto-provisioned mirroring store defaults.
    $outletMalt = Product::query()
        ->where('outlet_id', $this->outlet->id)
        ->where('name', 'Malt')
        ->firstOrFail();
    expect($outletMalt->price)->toBe(500_00);
    expect($outletMalt->cost)->toBe(200_00);
    expect($outletMalt->sku)->toBe('MALT-STR');
    expect($outletMalt->track_stock)->toBeTrue();
    expect($outletMalt->quantity_on_hand)->toBe(12);   // opening 0 + issuance +12

    // Store side dropped 40 → 28.
    expect($this->storeMalt->fresh()->quantity_on_hand)->toBe(28);

    // Request stamped.
    expect($approved->status)->toBe(StockRequestStatus::Approved);
    expect($approved->approved_quantity)->toBe(12);
    expect($approved->issued_pair_movement_id)->not->toBeNull();
});

it('approving a subsequent request reuses the existing outlet product (case-insensitive name match)', function (): void {
    // Pre-existing outlet product for MALT (mixed case).
    $outletMalt = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->outlet->id,
        'name' => 'MALT', 'price' => 550_00, 'cost' => 210_00,
        'track_stock' => true, 'quantity_on_hand' => 5,
    ]);

    $req = app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt,
        outlet:       $this->outlet,
        quantity:     8,
    );

    app(ApproveStockRequest::class)->handle(
        request:    $req,
        approvedBy: $this->keeper,
    );

    expect(Product::query()
        ->where('outlet_id', $this->outlet->id)
        ->where('name', 'MALT')
        ->count())->toBe(1);
    expect($outletMalt->fresh()->quantity_on_hand)->toBe(13);          // 5 + 8
    expect($this->storeMalt->fresh()->quantity_on_hand)->toBe(32);     // 40 - 8

    // Paired Issued movements exist.
    $movement = StockMovement::query()
        ->where('product_id', $outletMalt->id)
        ->where('reason', StockMovementReason::Issued)
        ->first();
    expect($movement)->not->toBeNull();
    expect($movement->delta)->toBe(8);
});

it('keeper can revise the approved quantity down before issuing', function (): void {
    $req = app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt,
        outlet:       $this->outlet,
        quantity:     12,
    );

    app(ApproveStockRequest::class)->handle(
        request:          $req,
        approvedBy:       $this->keeper,
        approvedQuantity: 8,
        note:             'Only 8 available today',
    );

    expect($this->storeMalt->fresh()->quantity_on_hand)->toBe(32);
    expect($req->fresh()->approved_quantity)->toBe(8);
    expect($req->fresh()->resolved_note)->toBe('Only 8 available today');
});

it('rejection sets status + resolved_note without touching stock', function (): void {
    $req = app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt,
        outlet:       $this->outlet,
        quantity:     50,
    );

    app(RejectStockRequest::class)->handle(
        request:    $req,
        rejectedBy: $this->keeper,
        note:       'Out of stock in the store',
    );

    expect($req->fresh()->status)->toBe(StockRequestStatus::Rejected);
    expect($req->fresh()->resolved_note)->toBe('Out of stock in the store');
    expect($this->storeMalt->fresh()->quantity_on_hand)->toBe(40);
    // No outlet product got auto-provisioned since the request never resolved as approved.
    expect(Product::query()->where('outlet_id', $this->outlet->id)->count())->toBe(0);
});

it('outlet can cancel a pending request', function (): void {
    $req = app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt,
        outlet:       $this->outlet,
        quantity:     3,
        requestedBy:  $this->outletOp,
    );

    app(CancelStockRequest::class)->handle($req, $this->outletOp);

    expect($req->fresh()->status)->toBe(StockRequestStatus::Cancelled);
});

it('cannot approve a resolved request', function (): void {
    $req = app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt, outlet: $this->outlet, quantity: 2,
    );
    app(RejectStockRequest::class)->handle($req, $this->keeper);

    app(ApproveStockRequest::class)->handle($req->fresh(), $this->keeper);
})->throws(RuntimeException::class, 'status is rejected');
