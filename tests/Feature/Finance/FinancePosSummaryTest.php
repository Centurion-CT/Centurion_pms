<?php

declare(strict_types=1);

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\OutletType;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'finance-pos']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();

    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->kitchen = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Kitchen', 'type' => OutletType::Kitchen,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small', 'price' => 1500, 'cost' => 800,
        'track_stock' => true, 'quantity_on_hand' => 100,
    ]);
    $this->jollof = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->kitchen->id,
        'name' => 'Jollof rice', 'price' => 3000,
        'track_stock' => false,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

function makeSale(Outlet $outlet, array $lines, Operator $op, string $method = 'cash', ?int $bookingId = null): void
{
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $outlet, operator: $op, cart: $lines, bookingId: $bookingId,
    );
    app(CompletePosSale::class)->handle($draft, $op, $method);
}

/* ------------------------------------------------------------------ */
/* posSummary                                                          */
/* ------------------------------------------------------------------ */

it('rolls up POS revenue + sale count + avg ticket in the window', function (): void {
    // 3 sales: 2 cash, 1 charge-to-room.
    makeSale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $this->operator);   // ₦3,000
    makeSale($this->kitchen, [['product_id' => $this->jollof->id, 'quantity' => 1]], $this->operator); // ₦3,000

    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $booking = Booking::factory()->for($this->tenant)->for($room)->confirmed()->create();
    makeSale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 4]], $this->operator, 'room', $booking->id); // ₦6,000

    $summary = app(FinanceSnapshot::class)->posSummary(
        Carbon::today()->subDay(), Carbon::today(),
    );

    expect($summary['total'])->toBe(12_000);
    expect($summary['count'])->toBe(3);
    expect($summary['avgTicket'])->toBe(4_000);
    expect($summary['chargeToRoomTotal'])->toBe(6_000);
    expect($summary['directTotal'])->toBe(6_000);
    expect($summary['chargeToRoomShare'])->toBe(50.0);
});

it('excludes voided and draft sales', function (): void {
    // Draft only.
    app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 5]],
    );

    // Completed then voided.
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 3]],
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');
    app(App\Actions\Pos\VoidPosSale::class)->handle($sale->fresh(), $this->operator, 'wrong');

    // Completed + kept.
    makeSale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $this->operator);

    $summary = app(FinanceSnapshot::class)->posSummary(
        Carbon::today()->subDay(), Carbon::today(),
    );

    // Only the kept sale (₦3,000).
    expect($summary['total'])->toBe(3_000);
    expect($summary['count'])->toBe(1);
});

/* ------------------------------------------------------------------ */
/* posByOutlet                                                         */
/* ------------------------------------------------------------------ */

it('breaks POS revenue down per outlet with share %', function (): void {
    makeSale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 4]], $this->operator);    // ₦6,000
    makeSale($this->kitchen, [['product_id' => $this->jollof->id, 'quantity' => 1]], $this->operator);  // ₦3,000

    $rows = app(FinanceSnapshot::class)->posByOutlet(
        Carbon::today()->subDay(), Carbon::today(),
    );

    expect($rows)->toHaveCount(2);
    // Bar first (higher revenue).
    expect($rows[0]['outlet_name'])->toBe('Bar');
    expect($rows[0]['revenue'])->toBe(6_000);
    expect($rows[0]['share'])->toBe(66.7);
    expect($rows[1]['outlet_name'])->toBe('Kitchen');
    expect($rows[1]['revenue'])->toBe(3_000);
    expect($rows[1]['share'])->toBe(33.3);
});

/* ------------------------------------------------------------------ */
/* posTopProducts                                                      */
/* ------------------------------------------------------------------ */

it('ranks top-selling products by revenue', function (): void {
    makeSale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 10]], $this->operator);   // ₦15,000
    makeSale($this->kitchen, [['product_id' => $this->jollof->id, 'quantity' => 3]], $this->operator);  // ₦9,000

    $products = app(FinanceSnapshot::class)->posTopProducts(
        Carbon::today()->subDay(), Carbon::today(),
    );

    expect($products)->toHaveCount(2);
    expect($products[0]['product_name'])->toBe('Guinness Small');
    expect($products[0]['units'])->toBe(10);
    expect($products[0]['revenue'])->toBe(15_000);
    expect($products[1]['product_name'])->toBe('Jollof rice');
});

it('posTopProducts survives a branch-scoped query (PosSaleLine reaches outlet via sale.outlet)', function (): void {
    // Regression: scopeByOutlet used to call whereHas('outlet', ...)
    // on the PosSaleLine query, but PosSaleLine has no outlet
    // relation - outlet is reachable via sale.outlet. As soon as a
    // property was selected on the sidebar (PropertyContext returned
    // non-null), Summary + Finance 500'd on any tenant with POS sales.
    app(\App\Services\Properties\PropertyContext::class)->switchTo($this->property);

    makeSale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 5]], $this->operator); // ₦7,500

    $products = app(FinanceSnapshot::class)->posTopProducts(
        Carbon::today()->subDay(), Carbon::today(),
    );

    expect($products)->toHaveCount(1);
    expect($products[0]['product_name'])->toBe('Guinness Small');
    expect($products[0]['revenue'])->toBe(7_500);
});

/* ------------------------------------------------------------------ */
/* Full page renders POS block                                         */
/* ------------------------------------------------------------------ */

it('renders the POS section in the Finance page for a manager', function (): void {
    $manager = Operator::factory()->for($this->tenant)->create([
        'role' => App\Enums\OperatorRole::Manager,
    ]);
    makeSale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $this->operator);

    Livewire\Livewire::actingAs($manager, 'web')
        ->test(App\Livewire\Dashboard\Finance\Index::class)
        ->assertSeeHtml('data-testid="finance-pos-summary"')
        ->assertSeeHtml('data-testid="pos-outlet-row-'.$this->bar->id.'"')
        ->assertSeeHtml('data-testid="pos-top-products"');
});
