<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PhysicalCount;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Inventory\DailyStockSheet;
use Illuminate\Support\Carbon;

/**
 * Pivoted date × product matrix for the outlet daily book. Asserts:
 *  - opening chains from previous day's closing
 *  - new / sold aggregate per day
 *  - physical count overrides expected, and today's closing becomes
 *    tomorrow's opening.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'matrix-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Bar',
        'type'        => OutletType::Bar,
    ]);
    $this->product = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'outlet_id'        => $this->outlet->id,
        'name'             => 'Origin Bitters',
        'price'            => 500_00,
        'cost'             => 350_00,
        'track_stock'      => true,
        'quantity_on_hand' => 23,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

it('chains opening across days and reflects sold + new per date', function (): void {
    // Baseline: 23 in the bar before day-1 window.
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::InitialStock,
        'delta'          => 23,
        'quantity_after' => 23,
        'occurred_at'    => Carbon::create(2026, 7, 31)->setTime(6, 0),
    ]);

    $day1 = Carbon::create(2026, 8, 1);
    $day2 = Carbon::create(2026, 8, 2);

    // Day 1: sold 2 → expected closing 21
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::Sold,
        'delta'          => -2,
        'quantity_after' => 21,
        'occurred_at'    => $day1->copy()->setTime(15, 0),
    ]);

    // Day 2: sold 1, new (issued from store) 5 → expected closing 25
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::Issued,
        'delta'          => 5,
        'quantity_after' => 26,
        'occurred_at'    => $day2->copy()->setTime(9, 0),
    ]);
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::Sold,
        'delta'          => -1,
        'quantity_after' => 25,
        'occurred_at'    => $day2->copy()->setTime(18, 0),
    ]);

    $matrix = app(DailyStockSheet::class)->buildMatrix(
        outletId: $this->outlet->id,
        from:     $day1,
        to:       $day2,
    );

    $day1Cell = $matrix['cells'][$day1->toDateString()][$this->product->id];
    $day2Cell = $matrix['cells'][$day2->toDateString()][$this->product->id];

    // Day 1
    expect($day1Cell['opening'])->toBe(23);
    expect($day1Cell['new'])->toBe(0);
    expect($day1Cell['total'])->toBe(23);
    expect($day1Cell['sold'])->toBe(2);
    expect($day1Cell['expected'])->toBe(21);
    expect($day1Cell['physical'])->toBeNull();
    expect($day1Cell['closing'])->toBe(21);

    // Day 2 — opening chains from day 1's closing (21)
    expect($day2Cell['opening'])->toBe(21);
    expect($day2Cell['new'])->toBe(5);
    expect($day2Cell['total'])->toBe(26);
    expect($day2Cell['sold'])->toBe(1);
    expect($day2Cell['expected'])->toBe(25);
    expect($day2Cell['closing'])->toBe(25);
});

it('physical count overrides expected and carries into next day opening', function (): void {
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::InitialStock,
        'delta'          => 10,
        'quantity_after' => 10,
        'occurred_at'    => Carbon::create(2026, 8, 4)->setTime(6, 0),
    ]);

    $day1 = Carbon::create(2026, 8, 5);
    $day2 = Carbon::create(2026, 8, 6);

    // Day 1: sold 2 → expected 8. Operator counts 7 physical (1 flat).
    StockMovement::query()->create([
        'tenant_id'      => $this->tenant->id,
        'product_id'     => $this->product->id,
        'operator_id'    => $this->manager->id,
        'reason'         => StockMovementReason::Sold,
        'delta'          => -2,
        'quantity_after' => 8,
        'occurred_at'    => $day1->copy()->setTime(20, 0),
    ]);
    PhysicalCount::query()->create([
        'tenant_id'    => $this->tenant->id,
        'product_id'   => $this->product->id,
        'count_date'   => $day1->toDateString(),
        'physical_qty' => 7,
    ]);

    $matrix = app(DailyStockSheet::class)->buildMatrix(
        outletId: $this->outlet->id,
        from:     $day1,
        to:       $day2,
    );

    $day1Cell = $matrix['cells'][$day1->toDateString()][$this->product->id];
    $day2Cell = $matrix['cells'][$day2->toDateString()][$this->product->id];

    // Day 1: physical wins over expected.
    expect($day1Cell['expected'])->toBe(8);
    expect($day1Cell['physical'])->toBe(7);
    expect($day1Cell['closing'])->toBe(7);
    expect($day1Cell['variance'])->toBe(-1);
    expect($day1Cell['remark'])->toBe('1 flat');

    // Day 2: opening chains from physical (7), not from expected (8).
    expect($day2Cell['opening'])->toBe(7);
});
