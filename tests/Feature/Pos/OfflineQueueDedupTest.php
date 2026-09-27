<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'dedup-pos']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small', 'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 20,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('flushing the same client_key twice only creates one sale', function (): void {
    $this->actingAs($this->manager, 'web');

    $payload = [
        'client_key' => 'phone-abc-123',
        'outlet_id' => $this->bar->id,
        'cart' => [['product_id' => $this->guinness->id, 'quantity' => 2]],
        'tenders' => [['method' => 'cash', 'amount' => 3_000]],
    ];

    $first = $this->postJson('/dashboard/pos/queue', $payload);
    $first->assertOk()->assertJsonPath('ok', true);
    $firstSaleId = $first->json('sale_id');

    $second = $this->postJson('/dashboard/pos/queue', $payload);
    $second->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('dedup', true)
        ->assertJsonPath('sale_id', $firstSaleId);

    expect(PosSale::query()->count())->toBe(1);
    expect($this->guinness->fresh()->quantity_on_hand)->toBe(18);
});

it('persists client_key on the sale row', function (): void {
    $this->actingAs($this->manager, 'web');

    $this->postJson('/dashboard/pos/queue', [
        'client_key' => 'phone-xyz-999',
        'outlet_id' => $this->bar->id,
        'cart' => [['product_id' => $this->guinness->id, 'quantity' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 1_500]],
    ])->assertOk();

    $sale = PosSale::query()->first();
    expect($sale->client_key)->toBe('phone-xyz-999');
});

it('different client_keys create separate sales', function (): void {
    $this->actingAs($this->manager, 'web');

    $base = [
        'outlet_id' => $this->bar->id,
        'cart' => [['product_id' => $this->guinness->id, 'quantity' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 1_500]],
    ];

    $this->postJson('/dashboard/pos/queue', ['client_key' => 'key-1'] + $base)->assertOk();
    $this->postJson('/dashboard/pos/queue', ['client_key' => 'key-2'] + $base)->assertOk();

    expect(PosSale::query()->count())->toBe(2);
    expect($this->guinness->fresh()->quantity_on_hand)->toBe(18);
});
