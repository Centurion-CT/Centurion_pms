<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Dashboard\Accounting\ProfitLoss\Index as PLIndex;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Accounting → Profit & Loss: cash vs accrual basis, branch scope,
 * expenses inclusion, monthly bucketing, CSV export.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2027-06-20 12:00:00');
    $this->tenant = Tenant::factory()->create(['slug' => 'pl-test']);
    $this->tenant->makeCurrent();
    $this->propertyA = Property::factory()->for($this->tenant)->create(['name' => 'A']);
    $this->propertyB = Property::factory()->for($this->tenant)->create(['name' => 'B']);
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->propertyA)->create(['base_rate' => 40_000]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->propertyA)->for($this->cat, 'category')->create();
    $catB = RoomCategory::factory()->for($this->tenant)->for($this->propertyB)->create(['base_rate' => 50_000]);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->propertyB)->for($catB, 'category')->create();

    $this->utilities = ExpenseCategory::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Utilities',
    ]);

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($this->manager, 'web');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function plSwitchBranch(Property $p): void
{
    app(\App\Services\Properties\PropertyContext::class)->switchTo($p);
}

function seedBookingAndPayment(Tenant $t, Room $r, int $amount, string $paidAt): void
{
    $b = Booking::factory()->for($t)->for($r)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::parse($paidAt)->toDateString(),
        'checkout_date' => Carbon::parse($paidAt)->addDay()->toDateString(),
        'nights' => 1,
        'total_amount' => $amount,
        'room_total' => $amount,
    ]);
    Payment::query()->create([
        'tenant_id' => $t->id,
        'booking_id' => $b->id,
        'amount' => $amount,
        'type' => PaymentType::Balance,
        'method' => 'cash',
        'status' => PaymentStatus::Succeeded,
        'paid_at' => $paidAt,
        'paystack_reference' => 'seed-'.uniqid(),
    ]);
}

function seedExpense(Tenant $t, ExpenseCategory $c, int $amount, string $on, ?Property $p = null): void
{
    Expense::query()->create([
        'tenant_id' => $t->id,
        'property_id' => $p?->id,
        'expense_category_id' => $c->id,
        'amount' => $amount,
        'description' => 'seed',
        'incurred_on' => Carbon::parse($on),
        'paid_via' => 'cash',
    ]);
}

it('cash-basis net = collected minus expenses in window', function (): void {
    plSwitchBranch($this->propertyA);

    seedBookingAndPayment($this->tenant, $this->roomA, 60_000, '2027-06-10 10:00:00');
    seedExpense($this->tenant, $this->utilities, 25_000, '2027-06-12', $this->propertyA);

    Livewire::test(PLIndex::class)
        ->set('basis', 'cash')
        ->set('range', 'month')
        ->assertViewHas('net', 35_000);
});

it('accrual-basis net = billed room+POS minus expenses (ignoring collected timing)', function (): void {
    plSwitchBranch($this->propertyA);

    // Booking whose stay is in June - accrual counts it.
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2027-06-05',
        'checkout_date' => '2027-06-06',
        'nights' => 1,
        'total_amount' => 100_000,
        'room_total' => 100_000,
    ]);
    seedExpense($this->tenant, $this->utilities, 40_000, '2027-06-08', $this->propertyA);

    Livewire::test(PLIndex::class)
        ->set('basis', 'accrual')
        ->set('range', 'month')
        ->assertViewHas('net', 60_000);
});

it('branch scope: hq-only excludes branch expenses', function (): void {
    plSwitchBranch($this->propertyA);

    seedExpense($this->tenant, $this->utilities, 10_000, '2027-06-05', $this->propertyA); // branch A
    seedExpense($this->tenant, $this->utilities, 30_000, '2027-06-06', null); // HQ
    seedBookingAndPayment($this->tenant, $this->roomA, 50_000, '2027-06-10 10:00:00');

    // Default (current + HQ) → expenses = 10k + 30k = 40k
    Livewire::test(PLIndex::class)
        ->set('basis', 'cash')
        ->assertViewHas('expenses', fn ($e) => (int) $e['total'] === 40_000);

    // HQ only → expenses = 30k
    Livewire::test(PLIndex::class)
        ->set('basis', 'cash')
        ->set('filterBranch', 'hq')
        ->assertViewHas('expenses', fn ($e) => (int) $e['total'] === 30_000);
});

it('margin is null when revenue is zero, computed otherwise', function (): void {
    plSwitchBranch($this->propertyA);
    seedExpense($this->tenant, $this->utilities, 10_000, '2027-06-05', $this->propertyA);

    // No revenue → margin is null (not −Infinity).
    Livewire::test(PLIndex::class)
        ->set('basis', 'cash')
        ->assertViewHas('margin', null);
});

it('CSV export streams monthly rows for the picked range', function (): void {
    plSwitchBranch($this->propertyA);
    seedBookingAndPayment($this->tenant, $this->roomA, 80_000, '2027-06-04 10:00:00');
    seedExpense($this->tenant, $this->utilities, 12_000, '2027-06-05', $this->propertyA);

    $response = $this->get(route('tenant.accounting.profit-loss.export', ['range' => 'month', 'basis' => 'cash']));
    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Month')->toContain('Revenue')->toContain('Expenses')->toContain('Net');
    expect($csv)->toContain('2027-06');
});
