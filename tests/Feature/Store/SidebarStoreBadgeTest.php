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

/**
 * The sidebar badge is rendered by the tenant-auth layout, not the
 * Livewire component itself — so these tests hit the full route to
 * exercise the layout render path.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'store-badge']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->store = Store::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Main store',
    ]);
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->storeMalt = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'name' => 'Malt', 'price' => 0, 'cost' => 20000,
        'track_stock' => true, 'quantity_on_hand' => 40,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

function storeUrl(Tenant $tenant): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').'/dashboard/store';
}

it('sidebar Store link shows a badge with the pending-request count', function (): void {
    app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt, outlet: $this->outlet, quantity: 3,
    );
    app(CreateStockRequest::class)->handle(
        storeProduct: $this->storeMalt, outlet: $this->outlet, quantity: 5,
    );

    $response = $this->get(storeUrl($this->tenant));
    $response->assertOk()->assertSee('nav-badge-store', false);

    // Badge content is whitespace-padded by the blade indent; check by
    // regex so the exact spacing doesn't matter.
    expect($response->getContent())->toMatch(
        '/data-testid="nav-badge-store"[^>]*>\s*2\s*</s'
    );
});

it('sidebar has no Store badge when no requests are pending', function (): void {
    $this->get(storeUrl($this->tenant))
        ->assertOk()
        ->assertDontSee('nav-badge-store', false);
});

it('badge caps at "99+" when there are more than 99 pending', function (): void {
    for ($i = 0; $i < 101; $i++) {
        app(CreateStockRequest::class)->handle(
            storeProduct: $this->storeMalt, outlet: $this->outlet, quantity: 1,
        );
    }

    $this->get(storeUrl($this->tenant))
        ->assertOk()
        ->assertSee('99+');
});
