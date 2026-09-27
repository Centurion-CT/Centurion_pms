<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockRequestStatus;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\Tenant;

/**
 * /api/mobile/v1/inventory + /stock-requests.
 * Outlet operator lists the store catalogue, requests stock,
 * tracks their own requests. Approve/receive flows stay web-only
 * for now.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'mobile-inv']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();

    $this->store = Store::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Central store', 'slug' => 'central', 'active' => true,
    ]);
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);

    // Store-side (source SKU)
    $this->storeBeer = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'name' => 'Guinness Small (store)', 'sku' => 'GUIN-SM',
        'price' => 0, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 500, 'active' => true,
    ]);

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->staff = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::PosStaff]);
    $this->bar->operators()->attach($this->staff->id);
});

afterEach(fn () => Tenant::forgetCurrent());

function bearerForInv(Operator $op): array
{
    $device = MobileDevice::query()->create([
        'tenant_id' => $op->tenant_id,
        'operator_id' => $op->id,
        'platform' => 'ios',
        'device_name' => 'T',
        'app_version' => '1.0.0',
        'paired_at' => now(),
        'last_seen_at' => now(),
    ]);
    $token = $op->createToken('mobile-device-'.$device->id, ['mobile:*'])->plainTextToken;

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function invUrl(Tenant $tenant, string $path): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').$path;
}

it('storeProducts returns store-side, active, stock-tracked products', function (): void {
    // Outlet-side product must NOT surface here.
    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small (bar)',
        'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 12, 'active' => true,
    ]);

    $response = $this->getJson(
        invUrl($this->tenant, '/api/mobile/v1/inventory/store-products'),
        bearerForInv($this->staff),
    );

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Guinness Small (store)']);
});

it('createRequest persists a StockRequest + returns it', function (): void {
    $response = $this->postJson(
        invUrl($this->tenant, '/api/mobile/v1/stock-requests'),
        [
            'outlet_id' => $this->bar->id,
            'product_id' => $this->storeBeer->id,
            'quantity' => 24,
            'note' => 'Weekend stocking',
        ],
        array_merge(bearerForInv($this->staff), ['Idempotency-Key' => 'stock-req-abc-1']),
    );

    $response->assertOk()->assertJson(['status' => 'ok']);
    expect($response->json('stock_request.quantity'))->toBe(24);
    expect(StockRequest::query()->count())->toBe(1);
});

it('createRequest refuses PosStaff hitting an outlet they are not attached to', function (): void {
    $kitchen = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Kitchen', 'type' => OutletType::Restaurant,
    ]);

    $response = $this->postJson(
        invUrl($this->tenant, '/api/mobile/v1/stock-requests'),
        [
            'outlet_id' => $kitchen->id,
            'product_id' => $this->storeBeer->id,
            'quantity' => 12,
        ],
        array_merge(bearerForInv($this->staff), ['Idempotency-Key' => 'stock-req-forbid-1']),
    );

    $response->assertStatus(403)->assertJson(['error' => 'outlet_forbidden']);
});

it('createRequest returns 422 when the source product is not a store product', function (): void {
    $outletProduct = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Wine (bar-only)',
        'price' => 4000, 'cost' => 2000,
        'track_stock' => true, 'quantity_on_hand' => 5, 'active' => true,
    ]);

    $response = $this->postJson(
        invUrl($this->tenant, '/api/mobile/v1/stock-requests'),
        [
            'outlet_id' => $this->bar->id,
            'product_id' => $outletProduct->id,
            'quantity' => 5,
        ],
        array_merge(bearerForInv($this->manager), ['Idempotency-Key' => 'not-a-store-key']),
    );

    $response->assertStatus(422)->assertJson(['error' => 'stock_request_failed']);
});

it('approveRequest flips the request to Approved + honours an adjusted quantity', function (): void {
    $req = StockRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'product_id' => $this->storeBeer->id, 'quantity' => 24,
        'status' => StockRequestStatus::Pending,
        'requested_by_operator_id' => $this->staff->id,
    ]);

    $response = $this->postJson(
        invUrl($this->tenant, '/api/mobile/v1/stock-requests/'.$req->id.'/approve'),
        ['approved_quantity' => 12, 'note' => 'Only 12 left in the store'],
        array_merge(bearerForInv($this->manager), ['Idempotency-Key' => 'approve-abc-1']),
    );

    $response->assertOk()->assertJson(['status' => 'ok']);
    expect($req->fresh()->status)->toBe(StockRequestStatus::Approved);
    expect($req->fresh()->approved_quantity)->toBe(12);
});

it('approveRequest refuses non-managers', function (): void {
    $req = StockRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'product_id' => $this->storeBeer->id, 'quantity' => 5,
        'status' => StockRequestStatus::Pending,
        'requested_by_operator_id' => $this->staff->id,
    ]);

    $response = $this->postJson(
        invUrl($this->tenant, '/api/mobile/v1/stock-requests/'.$req->id.'/approve'),
        [],
        array_merge(bearerForInv($this->staff), ['Idempotency-Key' => 'approve-forbid-1']),
    );

    $response->assertStatus(403)->assertJson(['error' => 'manager_only']);
    expect($req->fresh()->status)->toBe(StockRequestStatus::Pending);
});

it('rejectRequest flips the request to Rejected with the manager note', function (): void {
    $req = StockRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'product_id' => $this->storeBeer->id, 'quantity' => 24,
        'status' => StockRequestStatus::Pending,
        'requested_by_operator_id' => $this->staff->id,
    ]);

    $response = $this->postJson(
        invUrl($this->tenant, '/api/mobile/v1/stock-requests/'.$req->id.'/reject'),
        ['note' => 'Not this week — supplier delayed.'],
        array_merge(bearerForInv($this->manager), ['Idempotency-Key' => 'reject-abc-1']),
    );

    $response->assertOk();
    $fresh = $req->fresh();
    expect($fresh->status)->toBe(StockRequestStatus::Rejected);
    expect($fresh->resolved_note)->toBe('Not this week — supplier delayed.');
});

it('rejectRequest requires a note (422 without one)', function (): void {
    $req = StockRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'product_id' => $this->storeBeer->id, 'quantity' => 5,
        'status' => StockRequestStatus::Pending,
        'requested_by_operator_id' => $this->staff->id,
    ]);

    $response = $this->postJson(
        invUrl($this->tenant, '/api/mobile/v1/stock-requests/'.$req->id.'/reject'),
        [],
        array_merge(bearerForInv($this->manager), ['Idempotency-Key' => 'reject-noreason-1']),
    );

    $response->assertStatus(422);
});

it('requestsIndex filter=mine returns only the current operator requests', function (): void {
    StockRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'product_id' => $this->storeBeer->id, 'quantity' => 12,
        'status' => StockRequestStatus::Pending,
        'requested_by_operator_id' => $this->staff->id,
    ]);
    // A request from someone else — should NOT appear for staff on filter=mine.
    $otherStaff = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::PosStaff]);
    StockRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'product_id' => $this->storeBeer->id, 'quantity' => 3,
        'status' => StockRequestStatus::Pending,
        'requested_by_operator_id' => $otherStaff->id,
    ]);

    $response = $this->getJson(
        invUrl($this->tenant, '/api/mobile/v1/stock-requests?filter=mine'),
        bearerForInv($this->staff),
    );

    $response->assertOk();
    expect(count($response->json('data')))->toBe(1);
    expect($response->json('data.0.quantity'))->toBe(12);
});
