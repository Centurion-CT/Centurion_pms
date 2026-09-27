<?php

declare(strict_types=1);

use App\Actions\CreditLines\AddCreditLineCharge;
use App\Actions\CreditLines\RecordCreditLineSettlement;
use App\Enums\BookingChargeType;
use App\Enums\CreditLineStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\CreditLines\Index as CreditLinesIndex;
use App\Livewire\Dashboard\CreditLines\Show as CreditLineShow;
use App\Models\CreditLine;
use App\Models\CreditLineCharge;
use App\Models\CreditLineSettlement;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * House-account credit lines — add-a-charge + record-settlement
 * actions, outstanding math, and the Livewire detail-page wiring.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'credit-line-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->r306 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'room_number' => '306',
        'status' => RoomStatus::Active->value,
    ]);
    $this->r307 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'room_number' => '307',
        'status' => RoomStatus::Active->value,
    ]);
    $this->r999 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'room_number' => '999',
        'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->customer = Customer::create([
        'name' => 'Ada Okoye',
        'phone' => '08012345678',
        'email' => 'ada@x.test',
        'lifecycle' => CustomerLifecycle::Active,
    ]);

    // Open a working line with rooms 306, 307 attached (999 stays free).
    $this->line = CreditLine::query()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'name' => 'CEO tab',
        'status' => CreditLineStatus::Active,
        'spending_limit' => 500_000,
    ]);
    $this->line->rooms()->sync([$this->r306->id, $this->r307->id]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ─── Action layer ─────────────────────────────────────────────── */

it('adds a charge and reflects in chargedTotal / outstandingTotal', function (): void {
    app(AddCreditLineCharge::class)->handle(
        line: $this->line,
        type: BookingChargeType::FoodBeverage,
        description: 'Rooftop bar · 3 cocktails',
        amount: 15_000,
        operator: $this->manager,
        room: $this->r306,
    );

    $fresh = $this->line->refresh();
    expect($fresh->chargedTotal())->toBe(15_000);
    expect($fresh->settledTotal())->toBe(0);
    expect($fresh->outstandingTotal())->toBe(15_000);
});

it('refuses to add a charge to a room not attached to the line', function (): void {
    expect(fn () => app(AddCreditLineCharge::class)->handle(
        line: $this->line,
        type: BookingChargeType::FoodBeverage,
        description: 'x',
        amount: 5_000,
        operator: $this->manager,
        room: $this->r999,
    ))->toThrow(RuntimeException::class, 'isn\'t attached');
});

it('refuses to add a charge to a Suspended or Closed line', function (): void {
    $this->line->forceFill(['status' => CreditLineStatus::Suspended])->save();

    expect(fn () => app(AddCreditLineCharge::class)->handle(
        line: $this->line, type: BookingChargeType::FoodBeverage,
        description: 'x', amount: 5_000, operator: $this->manager,
    ))->toThrow(RuntimeException::class, 'not accepted');
});

it('records a settlement + reduces the outstanding', function (): void {
    app(AddCreditLineCharge::class)->handle(
        $this->line, BookingChargeType::FoodBeverage, 'dinner', 50_000, $this->manager, $this->r306,
    );

    app(RecordCreditLineSettlement::class)->handle(
        line: $this->line->refresh(),
        amount: 20_000,
        method: 'transfer',
        referenceNote: 'Bank ref 12345',
        operator: $this->manager,
    );

    $fresh = $this->line->refresh();
    expect($fresh->settledTotal())->toBe(20_000);
    expect($fresh->outstandingTotal())->toBe(30_000);
});

it('refuses to settle more than the outstanding', function (): void {
    app(AddCreditLineCharge::class)->handle(
        $this->line, BookingChargeType::FoodBeverage, 'dinner', 10_000, $this->manager, $this->r306,
    );

    expect(fn () => app(RecordCreditLineSettlement::class)->handle(
        line: $this->line->refresh(), amount: 15_000, method: 'cash', operator: $this->manager,
    ))->toThrow(RuntimeException::class, 'exceeds outstanding');
});

it('isOverLimit flips true once charged total exceeds the soft cap', function (): void {
    // 500k soft cap seeded in beforeEach.
    app(AddCreditLineCharge::class)->handle(
        $this->line, BookingChargeType::FoodBeverage, 'x', 400_000, $this->manager, $this->r306,
    );
    expect($this->line->refresh()->isOverLimit())->toBeFalse();

    app(AddCreditLineCharge::class)->handle(
        $this->line, BookingChargeType::FoodBeverage, 'y', 200_000, $this->manager, $this->r307,
    );
    expect($this->line->refresh()->isOverLimit())->toBeTrue();
});

/* ─── Livewire (Show) ──────────────────────────────────────────── */

it('detail page exposes running KPIs and both ledgers', function (): void {
    app(AddCreditLineCharge::class)->handle(
        $this->line, BookingChargeType::FoodBeverage, 'dinner', 50_000, $this->manager, $this->r306,
    );
    app(RecordCreditLineSettlement::class)->handle(
        $this->line->refresh(), 20_000, 'cash', null, $this->manager,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(CreditLineShow::class, ['creditLine' => $this->line])
        ->assertViewHas('chargedTotal', 50_000)
        ->assertViewHas('settledTotal', 20_000)
        ->assertViewHas('outstandingTotal', 30_000)
        ->assertViewHas('charges', fn ($c) => $c->count() === 1)
        ->assertViewHas('settlements', fn ($s) => $s->count() === 1);
});

it('add-charge form on the detail page persists a new charge', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CreditLineShow::class, ['creditLine' => $this->line])
        ->set('newChargeType', 'food_beverage')
        ->set('newChargeDescription', 'Sunday brunch')
        ->set('newChargeAmount', 25_000)
        ->set('newChargeRoomId', $this->r306->id)
        ->call('addCharge')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok'
            && ($p['message'] ?? null) === 'Charge added.');

    $charge = CreditLineCharge::query()->first();
    expect($charge->description)->toBe('Sunday brunch');
    expect((int) $charge->amount)->toBe(25_000);
    expect($charge->room_id)->toBe($this->r306->id);
});

it('record-settlement form persists a settlement + surfaces errors on over-pay', function (): void {
    app(AddCreditLineCharge::class)->handle(
        $this->line, BookingChargeType::FoodBeverage, 'x', 10_000, $this->manager, $this->r306,
    );

    // Legit partial settle.
    Livewire::actingAs($this->manager, 'web')
        ->test(CreditLineShow::class, ['creditLine' => $this->line->refresh()])
        ->set('settlementAmount', 4_000)
        ->set('settlementMethod', 'cash')
        ->call('recordSettlement')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok'
            && ($p['message'] ?? null) === 'Settlement recorded.');
    expect(CreditLineSettlement::query()->count())->toBe(1);

    // Over-pay refused.
    Livewire::actingAs($this->manager, 'web')
        ->test(CreditLineShow::class, ['creditLine' => $this->line->refresh()])
        ->set('settlementAmount', 99_000)
        ->set('settlementMethod', 'cash')
        ->call('recordSettlement')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err'
            && ($p['message'] ?? null) === 'Settlement amount ₦99,000 exceeds outstanding ₦6,000.');
});

/* ─── Livewire (Index) ─────────────────────────────────────────── */

it('index page sums running outstanding on the KPI + row', function (): void {
    app(AddCreditLineCharge::class)->handle(
        $this->line, BookingChargeType::FoodBeverage, 'x', 30_000, $this->manager, $this->r306,
    );
    app(RecordCreditLineSettlement::class)->handle(
        $this->line->refresh(), 12_000, 'cash', null, $this->manager,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(CreditLinesIndex::class)
        ->assertViewHas('totals', fn ($t) => $t['outstanding'] === 18_000)
        ->assertSee('CEO tab');
});
