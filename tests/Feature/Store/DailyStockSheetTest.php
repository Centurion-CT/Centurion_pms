<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\PhysicalCount;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Inventory\DailyStockSheet;
use Illuminate\Support\Carbon;

/**
 * Scripted-week test — one store product, three days of activity,
 * asserts the sheet row math for one specific day.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'sheet-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->store = Store::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Main store',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

it('opens with yesterday closing, aggregates today deltas, and auto-classifies remark', function (): void {
    $product = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'outlet_id'        => null,
        'store_id'         => $this->store->id,
        'name'             => 'Guinness case',
        'sku'              => 'GUIN-CS',
        'price'            => 1_200_00,   // ₦1,200
        'cost'             => 850_00,
        'track_stock'      => true,
        'quantity_on_hand' => 30,
    ]);

    $day = Carbon::create(2026, 8, 12);

    // Day-2 (a prior day): store received 30 cases (delta +30). Opening
    // for our target day should be 30.
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::Received,
        'delta'          => 30,
        'quantity_after' => 30,
        'occurred_at'    => $day->copy()->subDay()->setTime(9, 0),
    ]);

    // Day itself: received 12 more (+12), issued out 15 (-15).
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::Received,
        'delta'          => 12,
        'quantity_after' => 42,
        'occurred_at'    => $day->copy()->setTime(10, 0),
    ]);
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::Issued,
        'delta'          => -15,
        'quantity_after' => 27,
        'occurred_at'    => $day->copy()->setTime(15, 0),
    ]);

    // Physical count entered at end of day at 26 (one short).
    PhysicalCount::query()->create([
        'tenant_id'    => $this->tenant->id,
        'product_id'   => $product->id,
        'count_date'   => $day->toDateString(),
        'physical_qty' => 26,
        'operator_id'  => $this->manager->id,
    ]);

    $rows = app(DailyStockSheet::class)->build($day, storeId: $this->store->id);

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row['opening'])->toBe(30);
    expect($row['supply_in'])->toBe(12);
    expect($row['total'])->toBe(42);
    expect($row['consumption'])->toBe(15);
    expect($row['expected_balance'])->toBe(27);
    expect($row['physical_qty'])->toBe(26);
    expect($row['variance'])->toBe(-1);
    expect($row['remark'])->toBe('1 flat');
    expect($row['price'])->toBe(1_200_00);
    expect($row['total_naira'])->toBe(15 * 1_200_00);
});

it('returns empty rows when the store has no tracked products', function (): void {
    $rows = app(DailyStockSheet::class)->build(Carbon::today(), storeId: $this->store->id);

    expect($rows)->toBeEmpty();
});

it('auto-classifies a balanced count as "balanced" and a positive variance as "N over"', function (): void {
    $product = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'store_id'         => $this->store->id,
        'name'             => 'Origin Bitters',
        'price'            => 500_00,
        'cost'             => 350_00,
        'track_stock'      => true,
        'quantity_on_hand' => 20,
    ]);

    $day = Carbon::create(2026, 8, 13);

    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::Received,
        'delta'          => 20,
        'quantity_after' => 20,
        'occurred_at'    => $day->copy()->subDay(),
    ]);

    // Physical matches expected → balanced.
    PhysicalCount::query()->create([
        'tenant_id'    => $this->tenant->id,
        'product_id'   => $product->id,
        'count_date'   => $day->toDateString(),
        'physical_qty' => 20,
    ]);

    expect(app(DailyStockSheet::class)->build($day, storeId: $this->store->id)->first()['remark'])
        ->toBe('balanced');

    // Move the count up by 2 → "2 over".
    PhysicalCount::query()->where('product_id', $product->id)->update(['physical_qty' => 22]);

    expect(app(DailyStockSheet::class)->build($day, storeId: $this->store->id)->first()['remark'])
        ->toBe('2 over');
});
