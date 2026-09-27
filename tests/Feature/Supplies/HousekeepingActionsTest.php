<?php

declare(strict_types=1);

use App\Actions\Inventory\ApplyHousekeepingTurnover;
use App\Actions\Inventory\ReceiveFromLaundry;
use App\Actions\Inventory\SendToLaundry;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'housekeep-actions']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Guest Amenities',
        'type' => OutletType::Housekeeping,
        'tax_rate' => 0,
        'active' => true,
    ]);
    $this->soap = Product::query()->create([
        'tenant_id'          => $this->tenant->id,
        'outlet_id'          => $this->outlet->id,
        'name'               => 'Bath Soap 50g',
        'price'              => 200, 'cost' => 100,
        'track_stock'        => true, 'quantity_on_hand' => 40, 'low_stock_threshold' => 8,
        'usage_per_clean'    => 1,
        'is_rotational'      => false,
    ]);
    $this->bedsheet = Product::query()->create([
        'tenant_id'          => $this->tenant->id,
        'outlet_id'          => $this->outlet->id,
        'name'               => 'Bedsheet',
        'price'              => 4500, 'cost' => 3000,
        'track_stock'        => true, 'quantity_on_hand' => 20, 'low_stock_threshold' => 5,
        'usage_per_clean'    => 1,
        'is_rotational'      => true,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── Turnover consumption ───────────────────────────────────── */

it('deducts consumables outright and moves rotational units to In use on turnover', function (): void {
    $summary = app(ApplyHousekeepingTurnover::class)->handle(
        outlet:       $this->outlet,
        roomsCleaned: 3,
        noteRef:      'Rm 101, 102, 103',
        operator:     $this->manager,
    );

    expect($summary['lines_moved'])->toBe(2);
    expect($summary['total_units'])->toBe(6); // 1 soap × 3 + 1 sheet × 3

    $this->soap->refresh();
    $this->bedsheet->refresh();

    // Consumable: quantity_on_hand drops, qty_in_use untouched.
    expect($this->soap->quantity_on_hand)->toBe(37);
    expect($this->soap->qty_in_use)->toBe(0);

    // Rotational: quantity_on_hand drops AND qty_in_use bumps.
    expect($this->bedsheet->quantity_on_hand)->toBe(17);
    expect($this->bedsheet->qty_in_use)->toBe(3);

    // Ledger rows written for both, reason=Used.
    $rows = StockMovement::query()
        ->where('reason', StockMovementReason::Used)
        ->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('note')->unique()->all())
        ->toBe(['Turnover · 3 rooms · Rm 101, 102, 103']);
});

it('caps deductions at what is on hand and records the shortfall in the note', function (): void {
    $this->soap->update(['quantity_on_hand' => 2]);

    app(ApplyHousekeepingTurnover::class)->handle(
        outlet:       $this->outlet,
        roomsCleaned: 5,
        operator:     $this->manager,
    );

    $this->soap->refresh();
    expect($this->soap->quantity_on_hand)->toBe(0); // took the 2 we had

    $mv = StockMovement::query()
        ->where('product_id', $this->soap->id)
        ->where('reason', StockMovementReason::Used)
        ->first();
    expect($mv->delta)->toBe(-2);
    expect($mv->note)->toContain('shortfall 3');
});

it('rejects turnover on a non-Housekeeping outlet', function (): void {
    $bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
        'tax_rate' => 0, 'active' => true,
    ]);
    app(ApplyHousekeepingTurnover::class)->handle($bar, 1);
})->throws(RuntimeException::class, 'Housekeeping outlet');

/* ── Laundry rotation ───────────────────────────────────────── */

it('sends rotational units to laundry and receives them back to clean stock', function (): void {
    // Deploy 4 bedsheets to rooms first (in_use = 4).
    $this->bedsheet->update(['qty_in_use' => 4]);

    app(SendToLaundry::class)->handle($this->bedsheet, 4, $this->manager);
    $this->bedsheet->refresh();
    expect($this->bedsheet->qty_in_use)->toBe(0);
    expect($this->bedsheet->qty_at_laundry)->toBe(4);
    // No ledger row for SendToLaundry (clean stock unchanged).
    expect(StockMovement::query()->where('product_id', $this->bedsheet->id)->count())->toBe(0);

    app(ReceiveFromLaundry::class)->handle($this->bedsheet, 4, $this->manager, note: 'Back from ABC Laundry');
    $this->bedsheet->refresh();
    expect($this->bedsheet->qty_at_laundry)->toBe(0);
    expect($this->bedsheet->quantity_on_hand)->toBe(24); // 20 + 4 back

    $mv = StockMovement::query()
        ->where('product_id', $this->bedsheet->id)
        ->where('reason', StockMovementReason::ReturnedFromLaundry)
        ->first();
    expect($mv)->not->toBeNull();
    expect($mv->delta)->toBe(4);
    expect($mv->quantity_after)->toBe(24);
    expect($mv->note)->toBe('Back from ABC Laundry');
});

it('rejects SendToLaundry on a non-rotational product', function (): void {
    app(SendToLaundry::class)->handle($this->soap, 1);
})->throws(RuntimeException::class, "isn't a rotational item");

it('rejects over-sending more than qty_in_use to laundry', function (): void {
    $this->bedsheet->update(['qty_in_use' => 2]);
    app(SendToLaundry::class)->handle($this->bedsheet, 5);
})->throws(RuntimeException::class, 'cannot send 5');

it('rejects receiving more than qty_at_laundry', function (): void {
    $this->bedsheet->update(['qty_at_laundry' => 1]);
    app(ReceiveFromLaundry::class)->handle($this->bedsheet, 3);
})->throws(RuntimeException::class, 'cannot receive 3');
