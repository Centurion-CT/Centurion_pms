<?php

declare(strict_types=1);

use App\Actions\Inventory\DeleteOutlet;
use App\Actions\Inventory\DeleteProduct;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Activity;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use RuntimeException;

/**
 * Tenant admin-side deletion for POS outlets + products. Manager
 * only, cascade FKs sweep dependents, activity_log carries the
 * audit trail.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pos-del']);
    $this->tenant->makeCurrent();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->frontDesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);
    $this->property = App\Models\Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Test Bar',
        'type' => OutletType::Bar->value,
        'tax_rate' => 0,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('deletes an outlet + cascades products via FK', function (): void {
    Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'outlet_id' => $this->outlet->id,
        'name' => 'Beer', 'sku' => 'BEER01',
        'price' => 1_500, 'quantity' => 100, 'reorder_point' => 20,
    ]);

    $summary = app(DeleteOutlet::class)->handle($this->outlet, $this->manager);

    expect($summary['products'])->toBe(1);
    expect(Outlet::query()->find($this->outlet->id))->toBeNull();
    expect(Product::query()->where('outlet_id', $this->outlet->id)->count())->toBe(0);
});

it('stamps an activity_log row on outlet delete', function (): void {
    app(DeleteOutlet::class)->handle($this->outlet, $this->manager);

    $stamp = Activity::query()
        ->where('event', 'outlet_deleted')
        ->latest('id')->first();
    expect($stamp)->not->toBeNull();
    $props = $stamp->properties ?? collect();
    $props = is_array($props) ? $props : $props->toArray();
    expect((int) $props['outlet_id'])->toBe($this->outlet->id);
    expect($props['outlet_name'])->toBe('Test Bar');
});

it('refuses to delete an outlet when the operator is not a Manager', function (): void {
    expect(fn () => app(DeleteOutlet::class)->handle($this->outlet, $this->frontDesk))
        ->toThrow(RuntimeException::class, 'Only a Manager');
    // Outlet stays.
    expect(Outlet::query()->find($this->outlet->id))->not->toBeNull();
});

it('deletes a product + stamps an activity_log row', function (): void {
    $product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'outlet_id' => $this->outlet->id,
        'name' => 'Wine', 'sku' => 'WINE01',
        'price' => 5_000, 'quantity' => 20, 'reorder_point' => 5,
    ]);

    $summary = app(DeleteProduct::class)->handle($product, $this->manager);

    expect(Product::query()->find($product->id))->toBeNull();
    expect($summary['product_name'])->toBe('Wine');

    $stamp = Activity::query()->where('event', 'product_deleted')->latest('id')->first();
    expect($stamp)->not->toBeNull();
});

it('refuses to delete a product when the operator is not a Manager', function (): void {
    $product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'outlet_id' => $this->outlet->id,
        'name' => 'Beer', 'sku' => 'BEER02',
        'price' => 1_000, 'quantity' => 50, 'reorder_point' => 10,
    ]);

    expect(fn () => app(DeleteProduct::class)->handle($product, $this->frontDesk))
        ->toThrow(RuntimeException::class, 'Only a Manager');
    expect(Product::query()->find($product->id))->not->toBeNull();
});
