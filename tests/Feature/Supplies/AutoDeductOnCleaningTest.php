<?php

declare(strict_types=1);

use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\RoomInspectionStatus;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomInspection;
use App\Models\StockMovement;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'auto-deduct']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create(['room_number' => 101]);
    $this->outlet = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Guest Amenities',
        'type'        => OutletType::Housekeeping,
        'tax_rate'    => 0,
        'active'      => true,
    ]);
    $this->soap = Product::query()->create([
        'tenant_id'          => $this->tenant->id,
        'outlet_id'          => $this->outlet->id,
        'name'               => 'Bath Soap',
        'price'              => 200, 'cost' => 100,
        'track_stock'        => true, 'quantity_on_hand' => 30, 'low_stock_threshold' => 5,
        'usage_per_clean'    => 1,
    ]);
    $this->cleaner = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::HouseCleaner,
    ]);
    $this->inspection = RoomInspection::query()->create([
        'tenant_id'      => $this->tenant->id,
        'room_id'        => $this->room->id,
        'status'         => RoomInspectionStatus::Pending,
        'readiness_score' => 0,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('auto-deducts amenities on the first cleaning submission', function (): void {
    app(SubmitHousekeeperCleaning::class)->handle($this->inspection, $this->cleaner);

    $this->soap->refresh();
    expect($this->soap->quantity_on_hand)->toBe(29);

    $mv = StockMovement::query()
        ->where('product_id', $this->soap->id)
        ->where('reason', StockMovementReason::Used)
        ->first();
    expect($mv)->not->toBeNull();
    expect($mv->delta)->toBe(-1);
    expect($mv->note)->toContain('Inspection #'.$this->inspection->id);
    expect($mv->note)->toContain('101');
});

it('is idempotent — a re-submit does not double-deduct', function (): void {
    app(SubmitHousekeeperCleaning::class)->handle($this->inspection, $this->cleaner);
    app(SubmitHousekeeperCleaning::class)->handle($this->inspection, $this->cleaner);

    $this->soap->refresh();
    expect($this->soap->quantity_on_hand)->toBe(29); // still just one deduction

    $rows = StockMovement::query()
        ->where('product_id', $this->soap->id)
        ->where('reason', StockMovementReason::Used)
        ->count();
    expect($rows)->toBe(1);
});

it('never blocks cleaning submission if the deduction fails', function (): void {
    // Wipe the outlet so the auto-deduct branch has nothing to hit —
    // the cleaning submission should still succeed and advance status.
    Product::query()->where('outlet_id', $this->outlet->id)->delete();
    $this->outlet->delete();

    $result = app(SubmitHousekeeperCleaning::class)->handle($this->inspection, $this->cleaner);

    expect($result->status)->toBe(RoomInspectionStatus::CleaningDone);
    expect(StockMovement::query()->count())->toBe(0);
});
