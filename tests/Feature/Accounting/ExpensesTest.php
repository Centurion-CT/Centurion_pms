<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Accounting\Expenses\Index as ExpensesIndex;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Accounting → Expenses: create, filter, category management,
 * branch scoping (per-branch + HQ + all-branches), CSV export.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'accounting']);
    $this->tenant->makeCurrent();
    $this->propertyA = Property::factory()->for($this->tenant)->create(['name' => 'Branch A']);
    $this->propertyB = Property::factory()->for($this->tenant)->create(['name' => 'Branch B']);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager, 'name' => 'Owner',
    ]);
    $this->actingAs($this->manager, 'web');

    $this->utilities = ExpenseCategory::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Utilities', 'color' => '#F97316',
    ]);
    $this->fnb = ExpenseCategory::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'F&B supplies', 'color' => '#3B82F6',
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function switchToBranch(Property $p): void
{
    app(\App\Services\Properties\PropertyContext::class)->switchTo($p);
}

it('creates an expense attached to the current branch', function (): void {
    switchToBranch($this->propertyA);

    Livewire::test(ExpensesIndex::class)
        ->call('openCreate')
        ->set('newDescription', 'Diesel refill')
        ->set('newAmount', 45_000)
        ->set('newCategoryId', $this->utilities->id)
        ->set('newIncurredOn', now()->toDateString())
        ->set('newPaidVia', 'cash')
        ->set('newAttachToBranch', true)
        ->call('createExpense')
        ->assertSet('createOpen', false);

    $exp = Expense::query()->latest('id')->firstOrFail();
    expect($exp->amount)->toBe(45_000);
    expect($exp->property_id)->toBe($this->propertyA->id);
});

it('creates a chain-level (HQ) expense when the branch checkbox is off', function (): void {
    switchToBranch($this->propertyA);

    Livewire::test(ExpensesIndex::class)
        ->call('openCreate')
        ->set('newDescription', 'Group insurance premium')
        ->set('newAmount', 200_000)
        ->set('newCategoryId', $this->utilities->id)
        ->set('newIncurredOn', now()->toDateString())
        ->set('newAttachToBranch', false)
        ->call('createExpense');

    $exp = Expense::query()->latest('id')->firstOrFail();
    expect($exp->property_id)->toBeNull();
});

it('branch scope: current shows this-branch + HQ; hq shows only HQ; all shows every branch', function (): void {
    // Seed: one expense per branch + one HQ expense.
    Expense::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id, 'expense_category_id' => $this->utilities->id, 'amount' => 10_000, 'description' => 'A only', 'incurred_on' => today(), 'paid_via' => 'cash']);
    Expense::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyB->id, 'expense_category_id' => $this->utilities->id, 'amount' => 20_000, 'description' => 'B only', 'incurred_on' => today(), 'paid_via' => 'cash']);
    Expense::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => null,                'expense_category_id' => $this->utilities->id, 'amount' => 30_000, 'description' => 'HQ',     'incurred_on' => today(), 'paid_via' => 'cash']);

    switchToBranch($this->propertyA);

    // Default: current branch + HQ → 10k + 30k
    Livewire::test(ExpensesIndex::class)
        ->assertViewHas('filteredTotal', 40_000);

    // HQ only → 30k
    Livewire::test(ExpensesIndex::class)
        ->set('filterBranch', 'hq')
        ->assertViewHas('filteredTotal', 30_000);

    // All branches → 60k
    Livewire::test(ExpensesIndex::class)
        ->set('filterBranch', 'all')
        ->assertViewHas('filteredTotal', 60_000);
});

it('filter by category narrows the list + total', function (): void {
    Expense::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => null, 'expense_category_id' => $this->utilities->id, 'amount' => 12_000, 'description' => 'Electricity', 'incurred_on' => today(), 'paid_via' => 'cash']);
    Expense::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => null, 'expense_category_id' => $this->fnb->id,       'amount' => 34_000, 'description' => 'Bread flour', 'incurred_on' => today(), 'paid_via' => 'cash']);

    Livewire::test(ExpensesIndex::class)
        ->set('filterCategoryId', $this->fnb->id)
        ->assertViewHas('filteredTotal', 34_000);
});

it('createCategory adds a new category and rejects duplicate names', function (): void {
    Livewire::test(ExpensesIndex::class)
        ->set('newCategoryName', 'Cleaning')
        ->set('newCategoryColor', '#22C55E')
        ->call('createCategory')
        ->assertHasNoErrors();

    expect(ExpenseCategory::query()->where('name', 'Cleaning')->exists())->toBeTrue();

    // Second create with the same name should surface an error.
    Livewire::test(ExpensesIndex::class)
        ->set('newCategoryName', 'Cleaning')
        ->call('createCategory')
        ->assertHasErrors(['newCategoryName']);
});

it('deleteCategory refuses when expenses are attached', function (): void {
    Expense::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => null, 'expense_category_id' => $this->utilities->id, 'amount' => 100, 'description' => 'x', 'incurred_on' => today(), 'paid_via' => 'cash']);

    Livewire::test(ExpensesIndex::class)
        ->call('deleteCategory', $this->utilities->id);

    expect(ExpenseCategory::query()->find($this->utilities->id))->not->toBeNull();
});

it('CSV export streams the same rows the on-screen filter matches', function (): void {
    Expense::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyA->id, 'expense_category_id' => $this->utilities->id, 'amount' => 15_000, 'description' => 'A row', 'incurred_on' => today(), 'paid_via' => 'cash']);
    Expense::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->propertyB->id, 'expense_category_id' => $this->utilities->id, 'amount' => 25_000, 'description' => 'B row', 'incurred_on' => today(), 'paid_via' => 'cash']);

    $response = $this->get(route('tenant.accounting.expenses.export', ['branch' => 'all']));
    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('A row')->toContain('B row')->toContain('15000')->toContain('25000');
});
