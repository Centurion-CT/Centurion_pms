<?php

declare(strict_types=1);

use App\Actions\Inventory\SavePhysicalCount;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Stores\Index as StoreIndex;
use App\Models\Operator;
use App\Models\PhysicalCount;
use App\Models\Product;
use App\Models\Property;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'count-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->store = Store::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Main store',
    ]);
    $this->product = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'store_id'         => $this->store->id,
        'name'             => 'Guinness',
        'price'            => 0,
        'cost'             => 850_00,
        'track_stock'      => true,
        'quantity_on_hand' => 20,
    ]);
    $this->keeper = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::StockKeeper,
    ]);
});

it('inserts a new physical count row and can update it on second call', function (): void {
    $date = Carbon::create(2026, 8, 12);

    $first = app(SavePhysicalCount::class)->handle(
        product:     $this->product,
        date:        $date,
        physicalQty: 18,
        remark:      '2 flat',
        note:        'Two missing from crate 3',
        operator:    $this->keeper,
    );

    expect(PhysicalCount::count())->toBe(1);
    expect($first->physical_qty)->toBe(18);
    expect($first->remark)->toBe('2 flat');
    expect($first->operator_id)->toBe($this->keeper->id);

    // Update — same product + date → upsert, not a duplicate row.
    $second = app(SavePhysicalCount::class)->handle(
        product:     $this->product,
        date:        $date,
        physicalQty: 19,
        remark:      '1 flat',
    );

    expect(PhysicalCount::count())->toBe(1);
    expect($second->id)->toBe($first->id);
    expect($second->fresh()->physical_qty)->toBe(19);
    expect($second->fresh()->remark)->toBe('1 flat');
});

it('refuses a negative count', function (): void {
    app(SavePhysicalCount::class)->handle(
        product:     $this->product,
        date:        Carbon::today(),
        physicalQty: -1,
    );
})->throws(RuntimeException::class, 'cannot be negative');

it('refuses a product with track_stock off', function (): void {
    $service = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'store_id'         => $this->store->id,
        'name'             => 'Service item',
        'price'            => 0,
        'cost'             => 0,
        'track_stock'      => false,
        'quantity_on_hand' => 0,
    ]);

    app(SavePhysicalCount::class)->handle(
        product:     $service,
        date:        Carbon::today(),
        physicalQty: 3,
    );
})->throws(RuntimeException::class, 'track_stock is off');

it('saves via the Store landing saveCount() Livewire action', function (): void {
    $this->actingAs($this->keeper, 'web');

    $today = Carbon::today();

    Livewire::test(StoreIndex::class)
        ->assertSet('date', $today->toDateString())
        ->set('counts.'.$this->product->id.'.physical_qty', '17')
        ->set('counts.'.$this->product->id.'.remark', '')
        ->call('saveCount', $this->product->id)
        ->assertHasNoErrors()
        ->assertDispatched('count-saved');

    $count = PhysicalCount::query()->where('product_id', $this->product->id)->first();
    expect($count)->not->toBeNull();
    expect($count->physical_qty)->toBe(17);
    expect((string) $count->count_date->toDateString())->toBe($today->toDateString());
});
