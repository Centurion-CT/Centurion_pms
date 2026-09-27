<?php

declare(strict_types=1);

use App\Actions\Inventory\RecordStockIssuance;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Stores\Index as StoreIndex;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'store-history']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->store = Store::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Main store',
    ]);
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->kitchen = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Kitchen', 'type' => OutletType::Kitchen,
    ]);

    $this->storeMalt = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'name' => 'Malt', 'price' => 0, 'cost' => 20000,
        'track_stock' => true, 'quantity_on_hand' => 40,
    ]);
    $this->barMalt = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Malt', 'price' => 50000, 'cost' => 20000,
        'track_stock' => true, 'quantity_on_hand' => 0,
    ]);
    $this->kitchenMalt = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->kitchen->id,
        'name' => 'Malt', 'price' => 45000, 'cost' => 20000,
        'track_stock' => true, 'quantity_on_hand' => 0,
    ]);

    $this->keeper = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::StockKeeper, 'name' => 'Ada Keeper',
    ]);
    $this->actingAs($this->keeper, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('lists every Issued movement for today with outlet, quantity, and operator', function (): void {
    // Two issuances today — one to bar, one to kitchen.
    app(RecordStockIssuance::class)->handle(
        storeProduct: $this->storeMalt, outletProduct: $this->barMalt,
        quantity: 8, operator: $this->keeper, note: 'Bar weekend restock',
    );
    app(RecordStockIssuance::class)->handle(
        storeProduct: $this->storeMalt, outletProduct: $this->kitchenMalt,
        quantity: 3, operator: $this->keeper,
    );

    Livewire::test(StoreIndex::class)
        ->assertViewHas('consumptionHistory', function ($rows) {
            expect($rows)->toHaveCount(2);
            // Ordered latest-first.
            $outlets = $rows->pluck('outlet_name')->all();
            expect($outlets)->toBe(['Kitchen', 'Bar']);
            $bar = $rows->firstWhere('outlet_name', 'Bar');
            expect($bar['product_name'])->toBe('Malt');
            expect($bar['quantity'])->toBe(8);
            expect($bar['operator_name'])->toBe('Ada Keeper');
            expect($bar['note'])->toBe('Bar weekend restock');
            return true;
        });
});

it('shifting the date to a day with no issuances yields an empty history', function (): void {
    app(RecordStockIssuance::class)->handle(
        storeProduct: $this->storeMalt, outletProduct: $this->barMalt,
        quantity: 5, operator: $this->keeper,
    );

    Livewire::test(StoreIndex::class)
        ->call('shiftDay', 3)
        ->assertViewHas('consumptionHistory', fn ($rows) => $rows->isEmpty());
});

it('history is scoped to the picked date, not global', function (): void {
    // Today: one issuance.
    app(RecordStockIssuance::class)->handle(
        storeProduct: $this->storeMalt, outletProduct: $this->barMalt,
        quantity: 2, operator: $this->keeper,
    );

    // Backdate another movement to a specific past date by hand.
    $storeMovement = \App\Models\StockMovement::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->storeMalt->id,
        'reason' => \App\Enums\StockMovementReason::Issued,
        'delta' => -4,
        'quantity_after' => 34,
        'operator_id' => $this->keeper->id,
        'occurred_at' => Carbon::today()->subDays(2)->setTime(10, 0),
    ]);
    $outletMovement = \App\Models\StockMovement::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->kitchenMalt->id,
        'reason' => \App\Enums\StockMovementReason::Issued,
        'delta' => 4,
        'quantity_after' => 4,
        'operator_id' => $this->keeper->id,
        'occurred_at' => Carbon::today()->subDays(2)->setTime(10, 0),
        'source_type' => \App\Models\StockMovement::class,
        'source_id' => $storeMovement->id,
    ]);
    $storeMovement->forceFill([
        'source_type' => \App\Models\StockMovement::class,
        'source_id' => $outletMovement->id,
    ])->save();

    Livewire::test(StoreIndex::class)
        ->call('shiftDay', -2)
        ->assertViewHas('consumptionHistory', function ($rows) {
            expect($rows)->toHaveCount(1);
            expect($rows->first()['outlet_name'])->toBe('Kitchen');
            expect($rows->first()['quantity'])->toBe(4);
            return true;
        });
});
