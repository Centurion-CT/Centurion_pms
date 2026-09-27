<?php

declare(strict_types=1);

use App\Actions\Admin\PurgeTenantPosSales;
use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Admin\Tenants\Cleanup as AdminTenantsCleanup;
use App\Models\Activity;
use App\Models\Admin;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Super-admin POS-sale cleanup: same one-at-a-time flow the booking
 * cleanup uses, plus the extra manual sweeps a POS sale needs (stock
 * movements, wallet transactions, tied booking/credit-line charges,
 * polymorphic activity log rows).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pos-cleanup', 'name' => 'POS Cleanup Ltd']);
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
        'quantity_on_hand' => 20,
    ]);

    $this->superAdmin = Admin::factory()->super()->create();
    $this->supportAdmin = Admin::factory()->create();
});

afterEach(fn () => Tenant::forgetCurrent());

/** Complete a cash POS sale so we have realistic side-effects to sweep. */
function seedCompletedPosSale($outlet, $operator, $product): PosSale
{
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet:   $outlet,
        operator: $operator,
        cart:     [['product_id' => $product->id, 'quantity' => 2]],
    );
    return app(CompletePosSale::class)->handle(
        sale: $draft, operator: $operator, method: 'cash',
    );
}

/* ── Action layer ── */

it('purges a POS sale + cascades lines and sweeps payments, stock movements, activity log', function (): void {
    $sale = seedCompletedPosSale($this->outlet, $this->operator, $this->product);

    // Sanity — side effects exist.
    expect(Payment::query()->where('pos_sale_id', $sale->id)->count())->toBe(1);
    expect(StockMovement::query()->where('source_type', PosSale::class)
        ->where('source_id', $sale->id)->count())->toBeGreaterThan(0);
    expect(\App\Models\PosSaleLine::query()->where('pos_sale_id', $sale->id)->count())->toBe(1);

    Tenant::forgetCurrent();
    $summary = app(PurgeTenantPosSales::class)->handle(
        $this->tenant->fresh(), $this->superAdmin, [$sale->id],
    );

    expect($summary['sales'])->toBe(1);
    expect($summary['payments'])->toBe(1);
    expect($summary['sales_amount'])->toBe(3000);
    expect($summary['lines'])->toBe(1);
    expect($summary['stock_movements'])->toBeGreaterThan(0);

    $this->tenant->makeCurrent();
    expect(PosSale::query()->find($sale->id))->toBeNull();
    expect(\App\Models\PosSaleLine::query()->where('pos_sale_id', $sale->id)->count())->toBe(0);
    expect(Payment::query()->where('pos_sale_id', $sale->id)->count())->toBe(0);
    expect(StockMovement::query()->where('source_type', PosSale::class)
        ->where('source_id', $sale->id)->count())->toBe(0);
    expect(Activity::query()->where('subject_type', PosSale::class)
        ->where('subject_id', $sale->id)->count())->toBe(0);

    // Audit trail written against the tenant.
    $log = Activity::query()->where('event', 'tenant_pos_sales_purged')
        ->where('subject_type', Tenant::class)
        ->where('subject_id', $this->tenant->id)
        ->latest('id')->first();
    expect($log)->not->toBeNull();
    expect((int) $log->causer_id)->toBe($this->superAdmin->id);
});

it('refuses to touch a POS sale that belongs to a different tenant', function (): void {
    $other = Tenant::factory()->create(['slug' => 'pos-other']);
    $otherProp = Property::factory()->for($other)->create();
    $otherOp = Operator::factory()->for($other)->create();
    $otherOutlet = Outlet::query()->create([
        'tenant_id' => $other->id, 'property_id' => $otherProp->id,
        'name' => 'Other bar', 'type' => OutletType::Bar,
    ]);
    $otherProd = Product::query()->create([
        'tenant_id' => $other->id, 'outlet_id' => $otherOutlet->id,
        'name' => 'x', 'price' => 500, 'cost' => 200,
        'track_stock' => true, 'quantity_on_hand' => 5,
    ]);
    $other->makeCurrent();
    $foreignSale = seedCompletedPosSale($otherOutlet, $otherOp, $otherProd);

    Tenant::forgetCurrent();
    expect(fn () => app(PurgeTenantPosSales::class)->handle(
        $this->tenant->fresh(), $this->superAdmin, [$foreignSale->id],
    ))->toThrow(RuntimeException::class);

    $other->makeCurrent();
    expect(PosSale::query()->find($foreignSale->id))->not->toBeNull();
});

/* ── Livewire wiring ── */

it('super admin sees the POS tab + rows for the tenant', function (): void {
    $sale = seedCompletedPosSale($this->outlet, $this->operator, $this->product);
    Tenant::forgetCurrent();

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AdminTenantsCleanup::class, ['tenant' => $this->tenant])
        ->call('setTab', 'pos')
        ->assertSee($sale->reference)
        ->assertSeeHtml('data-testid="cleanup-pos-remove-'.$sale->id.'"');
});

it('purgePos refuses when the confirmation slug does not match', function (): void {
    $sale = seedCompletedPosSale($this->outlet, $this->operator, $this->product);
    Tenant::forgetCurrent();

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AdminTenantsCleanup::class, ['tenant' => $this->tenant])
        ->call('setTab', 'pos')
        ->call('openPosConfirm', $sale->id)
        ->set('confirmSlug', 'wrong')
        ->call('purgePos')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    $this->tenant->makeCurrent();
    expect(PosSale::query()->find($sale->id))->not->toBeNull();
});

it('purgePos fires when the correct slug is confirmed', function (): void {
    $sale = seedCompletedPosSale($this->outlet, $this->operator, $this->product);
    Tenant::forgetCurrent();

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AdminTenantsCleanup::class, ['tenant' => $this->tenant])
        ->call('setTab', 'pos')
        ->call('openPosConfirm', $sale->id)
        ->set('confirmSlug', $this->tenant->slug)
        ->call('purgePos')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok');

    $this->tenant->makeCurrent();
    expect(PosSale::query()->find($sale->id))->toBeNull();
});

it('openPosConfirm refuses to seed a sale from another tenant', function (): void {
    $other = Tenant::factory()->create(['slug' => 'pos-cross']);
    $otherProp = Property::factory()->for($other)->create();
    $otherOp = Operator::factory()->for($other)->create();
    $otherOutlet = Outlet::query()->create([
        'tenant_id' => $other->id, 'property_id' => $otherProp->id,
        'name' => 'x', 'type' => OutletType::Bar,
    ]);
    $otherProd = Product::query()->create([
        'tenant_id' => $other->id, 'outlet_id' => $otherOutlet->id,
        'name' => 'x', 'price' => 500, 'cost' => 200,
        'track_stock' => true, 'quantity_on_hand' => 5,
    ]);
    $other->makeCurrent();
    $foreignSale = seedCompletedPosSale($otherOutlet, $otherOp, $otherProd);
    Tenant::forgetCurrent();

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AdminTenantsCleanup::class, ['tenant' => $this->tenant])
        ->call('setTab', 'pos')
        ->call('openPosConfirm', $foreignSale->id)
        ->assertSet('pendingPosId', null)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');
});
