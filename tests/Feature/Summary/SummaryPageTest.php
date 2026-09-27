<?php

declare(strict_types=1);

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Actions\Pos\VoidPosSale;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Summary\Index as SummaryIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Reports\FinanceSnapshot;
use App\Services\Reports\SummaryWatchouts;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'summary']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 30_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager, 'name' => 'Manager Mo',
    ]);

    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Sky Bar', 'type' => OutletType::Bar,
    ]);
    $this->kitchen = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Kitchen', 'type' => OutletType::Kitchen,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small', 'price' => 1500,
        'track_stock' => true, 'quantity_on_hand' => 100,
    ]);
    $this->jollof = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->kitchen->id,
        'name' => 'Jollof rice', 'price' => 3000,
        'track_stock' => false,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function makeSummarySale(Outlet $outlet, array $lines, Operator $op): void
{
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $outlet, operator: $op, cart: $lines,
    );
    app(CompletePosSale::class)->handle($draft, $op, 'cash');
}

/* ── Access control ────────────────────────────────────────────── */

it('renders the summary page for a manager', function (): void {
    $response = $this->actingAs($this->manager, 'web')
        ->get('http://summary.keverna.test/dashboard/summary');

    $response->assertOk();
    $response->assertSeeLivewire(SummaryIndex::class);
});

it('redirects a reservation officer away', function (): void {
    $officer = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::ReservationOfficer,
    ]);

    $response = $this->actingAs($officer, 'web')
        ->get('http://summary.keverna.test/dashboard/summary');

    $response->assertRedirect();
});

it('redirects an inventory clerk away', function (): void {
    $clerk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::InventoryClerk,
    ]);

    $response = $this->actingAs($clerk, 'web')
        ->get('http://summary.keverna.test/dashboard/summary');

    $response->assertRedirect();
});

/* ── Filters ───────────────────────────────────────────────────── */

it('defaults to this_month + all departments', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->assertSet('period', 'this_month')
        ->assertSet('department', 'all');
});

it('setPeriod clears custom dates when picking a named preset', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->set('period', 'custom')
        ->set('fromDate', '2030-01-01')
        ->set('toDate', '2030-01-15')
        ->call('setPeriod', 'this_month')
        ->assertSet('period', 'this_month')
        ->assertSet('fromDate', '')
        ->assertSet('toDate', '');
});

it('setPeriod ignores unknown values', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->call('setPeriod', 'garbage')
        ->assertSet('period', 'this_month');
});

it('setDepartment writes any string (chip UI is the source of truth)', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->call('setDepartment', 'front_office')
        ->assertSet('department', 'front_office')
        ->call('setDepartment', 'outlet-'.$this->bar->id)
        ->assertSet('department', 'outlet-'.$this->bar->id);
});

/* ── KPI adaptation ────────────────────────────────────────────── */

it('shows total revenue when All is selected', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-06-10', 'total_amount' => 200_000,
    ]);
    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $this->manager);

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->assertSee('Total revenue')
        ->assertSee('POS sales')
        ->assertSee('Bookings');
});

it('shows outlet-specific KPI cards when an outlet chip is picked', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $this->manager);
    makeSummarySale($this->kitchen, [['product_id' => $this->jollof->id, 'quantity' => 1]], $this->manager);

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->set('department', 'outlet-'.$this->bar->id)
        ->assertSee('Outlet revenue')
        ->assertSee('Sales count')
        ->assertSee('Avg ticket')
        ->assertSee('Top seller')
        ->assertSee('Guinness Small');
});

it('front office scope shows booking-focused cards', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->set('department', 'front_office')
        ->assertSee('Booking revenue')
        ->assertSee('Occupancy')
        ->assertSee('Avg per booking');
});

/* ── Period math ───────────────────────────────────────────────── */

it('this_month window includes sales from the first of the month', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-20 10:00:00'));

    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 4]], $this->manager);

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->assertSet('period', 'this_month')
        ->assertSee('Sky Bar');
});

it('last_month window excludes today\'s sales', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    // Sale today (June 15) — should be OUT of scope for last_month.
    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 5]], $this->manager);

    // Set last_month and confirm revenue is zero for the bar outlet.
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->call('setPeriod', 'last_month')
        ->set('department', 'outlet-'.$this->bar->id)
        ->assertSee('Outlet revenue')
        // Zero revenue rendered as ₦0
        ->assertSee('&#8358;0', false);
});

/* ── Changes timeline ──────────────────────────────────────────── */

it('surfaces a price change made in the period', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    $this->guinness->update(['price' => 2000]);

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->assertSee('Changes in period')
        ->assertSee('Guinness Small')
        ->assertSee('Price');
});

it('scopes the changes timeline to the selected outlet', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    $this->guinness->update(['price' => 2000]);   // bar
    $this->jollof->update(['price' => 4000]);     // kitchen

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->set('department', 'outlet-'.$this->bar->id)
        ->assertSee('Guinness Small')
        ->assertDontSee('Jollof rice');
});

/* ── Staff filter ──────────────────────────────────────────────── */

it('renders the staff select with every operator', function (): void {
    $ada = Operator::factory()->for($this->tenant)->create([
        'name' => 'Ada Cashier', 'role' => OperatorRole::PosStaff,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->assertSee('Ada Cashier')
        ->assertSee('Manager Mo');
    expect($ada->exists)->toBeTrue();
});

it('staff filter scopes POS numbers to that operator', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    $ada = Operator::factory()->for($this->tenant)->create([
        'name' => 'Ada', 'role' => OperatorRole::PosStaff,
    ]);
    $bola = Operator::factory()->for($this->tenant)->create([
        'name' => 'Bola', 'role' => OperatorRole::PosStaff,
    ]);

    // Ada rings up ₦3,000; Bola rings up ₦6,000.
    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $ada);
    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 4]], $bola);

    // With Ada selected + bar outlet, revenue should be ₦3,000.
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->set('department', 'outlet-'.$this->bar->id)
        ->set('operatorFilter', (string) $ada->id)
        ->assertSee('&#8358;3,000', false)
        ->assertDontSee('&#8358;9,000', false);   // combined would show 9k
});

it('staff filter narrows the changes timeline', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    $ada = Operator::factory()->for($this->tenant)->create([
        'name' => 'Ada', 'role' => OperatorRole::Manager,
    ]);

    // Both changes happen — one by the default $this->manager (via update),
    // one attributed via activity()->causedBy($ada).
    activity()->causedBy($ada)->performedOn($this->guinness)
        ->withProperties(['attributes' => ['price' => 1800], 'old' => ['price' => 1500]])
        ->log('updated');
    activity()->causedBy($this->manager)->performedOn($this->jollof)
        ->withProperties(['attributes' => ['price' => 3500], 'old' => ['price' => 3000]])
        ->log('updated');

    // Filter to Ada — should see Guinness change only.
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->set('operatorFilter', (string) $ada->id)
        ->assertSee('Guinness Small')
        ->assertDontSee('Jollof rice');
});

/* ── Watch-outs ────────────────────────────────────────────────── */

it('renders the Watch-outs section with 4 metric tiles', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->assertSee('Watch-outs')
        ->assertSeeHtml('data-testid="summary-watch-voided"')
        ->assertSeeHtml('data-testid="summary-watch-wasted"')
        ->assertSeeHtml('data-testid="summary-watch-adjustments"')
        ->assertSeeHtml('data-testid="summary-watch-price-cuts"');
});

it('counts voided sales made in the period', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->manager,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 2]],
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->manager, 'cash');
    app(VoidPosSale::class)->handle($sale->fresh(), $this->manager, 'wrong item');

    $watchouts = app(SummaryWatchouts::class)
        ->build(Carbon::today()->startOfMonth(), Carbon::today()->endOfDay());

    expect($watchouts['voidedSales']['count'])->toBe(1);
    expect($watchouts['voidedSales']['amount'])->toBe(3_000);
});

it('counts downward price edits', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    // 1 cut + 1 raise — only the cut should count.
    $this->guinness->update(['price' => 1000]);   // 1500 → 1000: cut
    $this->jollof->update(['price' => 5000]);     // 3000 → 5000: raise

    $watchouts = app(SummaryWatchouts::class)
        ->build(Carbon::today()->startOfMonth(), Carbon::today()->endOfDay());

    expect($watchouts['priceCuts'])->toBe(1);
});

/* ── Growth ────────────────────────────────────────────────────── */

it('growth block compares this period against the prior window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-20 10:00:00'));

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->assertSee('Growth vs prior period')
        ->assertSeeHtml('data-testid="summary-growth-totalRevenue"')
        ->assertSeeHtml('data-testid="summary-growth-posRevenue"')
        ->assertSeeHtml('data-testid="summary-growth-occupancy"')
        ->assertSeeHtml('data-testid="summary-growth-bookings"');
});

/* ── Charts ────────────────────────────────────────────────────── */

it('dailySummary buckets sparse rows across the whole window with zeros', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-10 10:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-06-05', 'total_amount' => 120_000,
    ]);
    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $this->manager);   // today = 06-10

    $rows = app(FinanceSnapshot::class)
        ->dailySummary(Carbon::parse('2030-06-01'), Carbon::parse('2030-06-10'));

    expect($rows)->toHaveCount(10);
    expect($rows[0]['date'])->toBe('2030-06-01');
    expect($rows[9]['date'])->toBe('2030-06-10');

    // Booking row on 06-05 landed in the right bucket.
    $day5 = collect($rows)->firstWhere('date', '2030-06-05');
    expect($day5['roomRevenue'])->toBe(120_000);
    expect($day5['bookings'])->toBe(1);

    // POS row on today.
    $day10 = collect($rows)->firstWhere('date', '2030-06-10');
    expect($day10['posRevenue'])->toBe(3_000);
    expect($day10['posCount'])->toBe(1);

    // Empty day.
    $day3 = collect($rows)->firstWhere('date', '2030-06-03');
    expect($day3['roomRevenue'])->toBe(0);
    expect($day3['posRevenue'])->toBe(0);
});

it('dailySummary staff filter scopes POS but not booking numbers', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-10 10:00:00'));

    $ada = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::PosStaff]);
    $bola = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::PosStaff]);
    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $ada);   // ₦3,000
    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 4]], $bola);  // ₦6,000

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-06-10',
        'checkout_date' => '2030-06-11',
        'nights' => 1,
        'total_amount' => 50_000,
    ]);

    $rowsAda = app(FinanceSnapshot::class)
        ->dailySummary(Carbon::parse('2030-06-10'), Carbon::parse('2030-06-10'), $ada->id);
    expect($rowsAda[0]['posRevenue'])->toBe(3_000);
    expect($rowsAda[0]['roomRevenue'])->toBe(50_000);   // bookings NOT scoped by staff
});

it('dailySummary outlet filter zeroes room revenue for that scope', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-10 10:00:00'));

    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $this->manager);
    makeSummarySale($this->kitchen, [['product_id' => $this->jollof->id, 'quantity' => 1]], $this->manager);

    $rowsBar = app(FinanceSnapshot::class)
        ->dailySummary(Carbon::parse('2030-06-10'), Carbon::parse('2030-06-10'), null, $this->bar->id);

    expect($rowsBar[0]['posRevenue'])->toBe(3_000);   // bar only
    expect($rowsBar[0]['roomRevenue'])->toBe(0);      // room columns zeroed under outlet scope
});

it('renders the trend chart section and per-outlet bar chart on the page', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 10:00:00'));

    makeSummarySale($this->bar, [['product_id' => $this->guinness->id, 'quantity' => 2]], $this->manager);

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->assertSee('Revenue trend')
        ->assertSeeHtml('data-testid="summary-trend"')
        ->assertSeeHtml('data-testid="summary-breakdown-row"')
        ->assertSeeHtml('data-testid="summary-kpi-spark"');   // at least one KPI card has a spark
});
