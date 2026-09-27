<?php

declare(strict_types=1);

use App\Actions\Admin\PurgeTenantPosCatalog;
use App\Enums\OutletType;
use App\Models\Activity;
use App\Models\Admin;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;

/**
 * Super-admin catalog purge - wipes every outlet, product, store on
 * a tenant + everything they reference. Sibling to PurgeTenantPosSales
 * for full onboarding-reset.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'catalog-purge']);
    $this->tenant->makeCurrent();
    $this->admin = Admin::factory()->create(['name' => 'Ada Admin']);
    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('wipes every outlet + product + store on the tenant', function (): void {
    $this->tenant->makeCurrent();
    $property = App\Models\Property::factory()->for($this->tenant)->create();
    $outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $property->id,
        'name' => 'Test Bar', 'type' => OutletType::Bar->value, 'tax_rate' => 0,
    ]);
    Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'outlet_id' => $outlet->id,
        'name' => 'Beer', 'sku' => 'BEER01',
        'price' => 1_500, 'quantity' => 100, 'reorder_point' => 20,
    ]);
    Store::factory()->for($this->tenant)->create(['name' => 'Central Store']);
    Tenant::forgetCurrent();

    $summary = app(PurgeTenantPosCatalog::class)->handle($this->tenant, $this->admin);

    expect($summary['outlets'])->toBe(1);
    expect($summary['products'])->toBe(1);
    expect($summary['stores'])->toBe(1);

    $this->tenant->makeCurrent();
    expect(Outlet::query()->count())->toBe(0);
    expect(Product::query()->count())->toBe(0);
    expect(Store::query()->count())->toBe(0);
});

it('stamps an admin activity_log entry with the purge summary', function (): void {
    $this->tenant->makeCurrent();
    $property = App\Models\Property::factory()->for($this->tenant)->create();
    Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $property->id,
        'name' => 'X', 'type' => OutletType::Bar->value, 'tax_rate' => 0,
    ]);
    Tenant::forgetCurrent();

    app(PurgeTenantPosCatalog::class)->handle($this->tenant, $this->admin);

    $stamp = Activity::query()->withoutGlobalScope('tenant')
        ->where('event', 'tenant_pos_catalog_purged')
        ->latest('id')->first();
    expect($stamp)->not->toBeNull();
    $props = $stamp->properties ?? collect();
    $props = is_array($props) ? $props : $props->toArray();
    expect($props['outlets'])->toBe(1);
});

it('refuses when the tenant has no catalog', function (): void {
    expect(fn () => app(PurgeTenantPosCatalog::class)->handle($this->tenant, $this->admin))
        ->toThrow(RuntimeException::class, 'no POS catalog');
});
