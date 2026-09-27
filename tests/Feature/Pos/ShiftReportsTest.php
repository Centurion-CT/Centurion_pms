<?php

declare(strict_types=1);

use App\Actions\Pos\CloseOutletShift;
use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Actions\Pos\OpenOutletShift;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\OutletShift;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Pos\ShiftReportBuilder;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'shift-report']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Bar',
        'type' => OutletType::Bar,
    ]);
    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'outlet_id' => $this->outlet->id,
        'name' => 'Guinness Small',
        'price' => 1500,
        'cost' => 800,
        'track_stock' => true,
        'quantity_on_hand' => 20,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('opens a shift and snapshots opening stock for every tracked product', function (): void {
    $shift = app(OpenOutletShift::class)->handle($this->outlet, $this->operator);

    expect($shift->isOpen())->toBeTrue();
    expect($shift->operator_id)->toBe($this->operator->id);

    $opening = $shift->snapshots()->where('kind', 'opening')->get();
    expect($opening)->toHaveCount(1);
    expect((int) $opening->first()->quantity_on_hand)->toBe(20);
    expect($opening->first()->product_name)->toBe('Guinness Small');
});

it('refuses to open a second shift while one is open on the outlet', function (): void {
    app(OpenOutletShift::class)->handle($this->outlet, $this->operator);

    expect(fn () => app(OpenOutletShift::class)->handle($this->outlet, $this->operator))
        ->toThrow(RuntimeException::class, 'already have an open shift');
});

it('closes the shift, snapshots closing stock, and stamps closed_by', function (): void {
    $shift = app(OpenOutletShift::class)->handle($this->outlet, $this->operator);

    // Sell 3 bottles inside the shift window
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet,
        operator: $this->operator,
        cart: [['product_id' => $this->product->id, 'quantity' => 3]],
    );
    app(CompletePosSale::class)->handle(sale: $draft, operator: $this->operator, method: 'cash');

    $closed = app(CloseOutletShift::class)->handle(
        shift: $shift,
        closer: $this->operator,
        notes: 'end of night',
    );

    expect($closed->closed_at)->not->toBeNull();
    expect($closed->closed_by_operator_id)->toBe($this->operator->id);
    expect($closed->notes)->toBe('end of night');

    $closing = $closed->snapshots()->where('kind', 'closing')->first();
    expect($closing)->not->toBeNull();
    expect((int) $closing->quantity_on_hand)->toBe(17);  // 20 opening − 3 sold
});

it('blocks a random operator from closing another salesperson\'s shift', function (): void {
    $shift = app(OpenOutletShift::class)->handle($this->outlet, $this->operator);
    $stranger = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);

    expect(fn () => app(CloseOutletShift::class)->handle(shift: $shift, closer: $stranger))
        ->toThrow(RuntimeException::class, 'Only the salesperson');
});

it('lets a Manager force-close another salesperson\'s shift and records the reason', function (): void {
    $shift = app(OpenOutletShift::class)->handle($this->outlet, $this->operator);

    $closed = app(CloseOutletShift::class)->handle(
        shift: $shift,
        closer: $this->manager,
        reason: 'till handoff',
    );

    expect($closed->closed_by_operator_id)->toBe($this->manager->id);
    expect($closed->close_reason)->toBe('till handoff');
});

it('builds a report with sales / tenders / stock reconciliation', function (): void {
    $shift = app(OpenOutletShift::class)->handle($this->outlet, $this->operator);

    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet,
        operator: $this->operator,
        cart: [['product_id' => $this->product->id, 'quantity' => 2]],
    );
    app(CompletePosSale::class)->handle(sale: $draft, operator: $this->operator, method: 'cash');

    app(CloseOutletShift::class)->handle(shift: $shift, closer: $this->operator);

    $report = app(ShiftReportBuilder::class)->build($shift->fresh(['snapshots']));

    expect($report['is_open'])->toBeFalse();
    expect($report['sales']['count'])->toBe(1);
    expect($report['sales']['gross'])->toBe(3000);
    expect($report['sales']['net'])->toBe(3000);
    expect($report['sales']['avg_ticket'])->toBe(3000);

    $cash = collect($report['tenders'])->firstWhere('method', 'cash');
    expect($cash)->not->toBeNull();
    expect($cash['amount'])->toBe(3000);

    expect($report['stock'])->toHaveCount(1);
    $row = $report['stock'][0];
    expect($row['name'])->toBe('Guinness Small');
    expect($row['opening_qty'])->toBe(20);
    expect($row['sold_qty'])->toBe(2);
    expect($row['closing_qty'])->toBe(18);
    expect($row['expected_closing'])->toBe(18);
    expect($row['variance'])->toBe(0);
});

it('exports a CSV for a closed shift via the export controller', function (): void {
    $shift = app(OpenOutletShift::class)->handle($this->outlet, $this->operator);
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet,
        operator: $this->operator,
        cart: [['product_id' => $this->product->id, 'quantity' => 1]],
    );
    app(CompletePosSale::class)->handle(sale: $draft, operator: $this->operator, method: 'cash');
    app(CloseOutletShift::class)->handle(shift: $shift, closer: $this->operator);

    $this->actingAs($this->operator, 'web');

    $response = $this->get(route('tenant.pos.shifts.export', $shift));
    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('SALES SUMMARY');
    expect($body)->toContain('BY TENDER');
    expect($body)->toContain('STOCK RECONCILIATION');
    expect($body)->toContain('Guinness Small');
});
