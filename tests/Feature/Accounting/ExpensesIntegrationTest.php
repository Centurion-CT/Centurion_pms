<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Dashboard\Finance\Index as FinanceIndex;
use App\Livewire\Dashboard\Summary\Index as SummaryIndex;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Integration coverage - expenses logged via Accounting must show
 * up on Summary + Finance without divergence.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2027-08-10 12:00:00');
    $this->tenant = Tenant::factory()->create(['slug' => 'exp-integration']);
    $this->tenant->makeCurrent();
    $this->propertyA = Property::factory()->for($this->tenant)->create(['name' => 'A']);
    $this->propertyB = Property::factory()->for($this->tenant)->create(['name' => 'B']);
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->propertyA)->create(['base_rate' => 40_000]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->propertyA)->for($this->cat, 'category')->create();

    $this->utilities = ExpenseCategory::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Utilities',
    ]);

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
    app(\App\Services\Properties\PropertyContext::class)->switchTo($this->propertyA);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function seedIntegrationPayment(Tenant $t, Room $r, int $amount, string $paidAt): void
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

it('FinanceSnapshot::expenseTotal scopes to current branch + HQ', function (): void {
    Expense::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id,
        'expense_category_id' => $this->utilities->id, 'amount' => 10_000,
        'description' => 'A only', 'incurred_on' => '2027-08-05', 'paid_via' => 'cash',
    ]);
    Expense::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->propertyB->id,
        'expense_category_id' => $this->utilities->id, 'amount' => 20_000,
        'description' => 'B only', 'incurred_on' => '2027-08-05', 'paid_via' => 'cash',
    ]);
    Expense::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => null,
        'expense_category_id' => $this->utilities->id, 'amount' => 30_000,
        'description' => 'HQ',     'incurred_on' => '2027-08-05', 'paid_via' => 'cash',
    ]);

    // Current branch = A → total should be A (10k) + HQ (30k) = 40k.
    $total = app(FinanceSnapshot::class)->expenseTotal(
        Carbon::parse('2027-08-01'), Carbon::parse('2027-08-31')
    );
    expect($total)->toBe(40_000);
});

it('FinanceSnapshot::dailyExpenseSeries emits dense rows with per-day totals', function (): void {
    Expense::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id,
        'expense_category_id' => $this->utilities->id, 'amount' => 5_000,
        'description' => 'x', 'incurred_on' => '2027-08-05', 'paid_via' => 'cash',
    ]);

    $series = app(FinanceSnapshot::class)->dailyExpenseSeries(
        Carbon::parse('2027-08-04'), Carbon::parse('2027-08-06')
    );
    expect(count($series))->toBe(3);
    expect($series[0])->toBe(['date' => '2027-08-04', 'expenses' => 0]);
    expect($series[1])->toBe(['date' => '2027-08-05', 'expenses' => 5_000]);
    expect($series[2])->toBe(['date' => '2027-08-06', 'expenses' => 0]);
});

it('Summary surfaces the Expenses KPI + Net (cash) card', function (): void {
    seedIntegrationPayment($this->tenant, $this->roomA, 60_000, '2027-08-05 10:00:00');
    Expense::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id,
        'expense_category_id' => $this->utilities->id, 'amount' => 20_000,
        'description' => 'Diesel', 'incurred_on' => '2027-08-06', 'paid_via' => 'cash',
    ]);

    Livewire::test(SummaryIndex::class)
        ->assertSee('Expenses')
        ->assertSee('Net (cash)')
        ->assertSee('40,000');   // 60k revenue − 20k expenses
});

it('Finance KPI strip adds Expenses + Net profit cards; cashflow includes an Expenses column', function (): void {
    seedIntegrationPayment($this->tenant, $this->roomA, 80_000, '2027-08-04 10:00:00');
    Expense::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id,
        'expense_category_id' => $this->utilities->id, 'amount' => 15_000,
        'description' => 'Utilities', 'incurred_on' => '2027-08-05', 'paid_via' => 'cash',
    ]);

    Livewire::test(FinanceIndex::class)
        ->assertSee('Expenses')
        ->assertSee('Net profit')
        ->assertSeeHtml('data-testid="kpi-expenses"')
        ->assertSeeHtml('data-testid="kpi-net-profit"');
});
