<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Accounting\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Operator;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Expenses — the first app under Accounting. Multi-branch aware:
 * every list + insight scopes to the operator's currently-selected
 * branch (PropertyContext), plus HQ (property_id = null) rows so a
 * chain-level cost like a group insurance premium always shows.
 *
 * Actions on this page:
 *  - Create expense (form modal, category-scoped)
 *  - Manage categories (create + rename)
 *  - Filter by date range, category, paid_via
 *  - Insights strip (this month total, prior month, top category)
 *  - Export CSV — hands off to ExpenseExportController with the
 *    same filter set
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    // ── Filters (URL-persisted so a shared link honours them) ─────
    #[Url(as: 'from', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'to', except: '')]
    public string $filterTo = '';

    #[Url(as: 'cat', except: 0)]
    public int $filterCategoryId = 0;

    #[Url(as: 'via', except: '')]
    public string $filterPaidVia = '';

    #[Url(as: 'branch', except: '')]
    /** 'current' = property scope, 'hq' = HQ only, 'all' = all branches */
    public string $filterBranch = 'current';

    // ── Create-expense form state ─────────────────────────────────
    public bool $createOpen = false;
    public string $newDescription = '';
    public int $newAmount = 0;
    public int $newCategoryId = 0;
    public string $newIncurredOn = '';
    public string $newPaidVia = 'cash';
    public string $newVendor = '';
    public string $newReference = '';
    public string $newNotes = '';
    /** If null, expense is chain-level (HQ); else attached to current branch. */
    public bool $newAttachToBranch = true;

    // ── Categories manager ────────────────────────────────────────
    public bool $categoriesOpen = false;
    public string $newCategoryName = '';
    public string $newCategoryColor = '#F97316';

    protected function currentPropertyId(): ?int
    {
        return app(PropertyContext::class)->current()?->getKey();
    }

    public function openCreate(): void
    {
        $this->createOpen = true;
        $this->newDescription = '';
        $this->newAmount = 0;
        $this->newCategoryId = ExpenseCategory::query()->orderBy('id')->first()?->id ?? 0;
        $this->newIncurredOn = Carbon::today()->toDateString();
        $this->newPaidVia = 'cash';
        $this->newVendor = '';
        $this->newReference = '';
        $this->newNotes = '';
        $this->newAttachToBranch = true;
    }

    public function closeCreate(): void
    {
        $this->createOpen = false;
    }

    public function createExpense(): void
    {
        $data = $this->validate([
            'newDescription'  => ['required', 'string', 'max:240'],
            'newAmount'       => ['required', 'integer', 'min:1', 'max:999999999'],
            'newCategoryId'   => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'newIncurredOn'   => ['required', 'date'],
            'newPaidVia'      => ['required', Rule::in(['cash', 'transfer', 'card', 'pos', 'other'])],
            'newVendor'       => ['nullable', 'string', 'max:120'],
            'newReference'    => ['nullable', 'string', 'max:60'],
            'newNotes'        => ['nullable', 'string', 'max:2000'],
            'newAttachToBranch' => ['boolean'],
        ]);

        $tenantId = (int) \App\Models\Tenant::current()?->id;
        $operator = auth('web')->user();

        // Chain-level (HQ) expense uses property_id = null; branch
        // expense uses the operator's current branch. A single-branch
        // org with no explicit branch still gets a null property_id,
        // which reads as "the whole business" in reports.
        $propertyId = $data['newAttachToBranch']
            ? $this->currentPropertyId()
            : null;

        Expense::query()->create([
            'tenant_id' => $tenantId,
            'property_id' => $propertyId,
            'expense_category_id' => (int) $data['newCategoryId'],
            'amount' => (int) $data['newAmount'],
            'description' => (string) $data['newDescription'],
            'notes' => $data['newNotes'] !== '' ? $data['newNotes'] : null,
            'incurred_on' => Carbon::parse($data['newIncurredOn']),
            'paid_via' => (string) $data['newPaidVia'],
            'vendor' => $data['newVendor'] !== '' ? $data['newVendor'] : null,
            'reference' => $data['newReference'] !== '' ? $data['newReference'] : null,
            'created_by_operator_id' => $operator instanceof Operator ? $operator->id : null,
        ]);

        activity('accounting')
            ->causedBy($operator)
            ->event('expense_created')
            ->withProperties(['amount' => (int) $data['newAmount'], 'category_id' => (int) $data['newCategoryId']])
            ->log('Expense logged: '.$data['newDescription'].' ₦'.number_format((int) $data['newAmount']));

        $this->closeCreate();
        $this->dispatch('notify', title: 'Expense logged', message: '₦'.number_format((int) $data['newAmount']).' added to the books.', kind: 'ok');
    }

    public function deleteExpense(int $id): void
    {
        $expense = Expense::query()->find($id);
        if ($expense === null) return;
        $expense->delete();
        $this->dispatch('notify', title: 'Removed', message: 'Expense line deleted.', kind: 'ok');
    }

    public function openCategories(): void
    {
        $this->categoriesOpen = true;
        $this->newCategoryName = '';
        $this->newCategoryColor = '#F97316';
    }

    public function closeCategories(): void
    {
        $this->categoriesOpen = false;
    }

    public function createCategory(): void
    {
        $data = $this->validate([
            'newCategoryName'  => ['required', 'string', 'max:80'],
            'newCategoryColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $tenantId = (int) \App\Models\Tenant::current()?->id;
        try {
            ExpenseCategory::query()->create([
                'tenant_id' => $tenantId,
                'name' => trim($data['newCategoryName']),
                'color' => $data['newCategoryColor'] ?: null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $this->addError('newCategoryName', 'That name is already used.');
            return;
        }
        $this->newCategoryName = '';
    }

    public function deleteCategory(int $id): void
    {
        $cat = ExpenseCategory::query()->find($id);
        if ($cat === null) return;
        if ($cat->expenses()->count() > 0) {
            $this->dispatch('notify', title: "Can't delete", message: 'Category has expenses. Reassign them first.', kind: 'err');
            return;
        }
        $cat->delete();
    }

    protected function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = Expense::query();

        // Branch scoping — three modes so an HQ finance user can flip
        // to "all branches" without leaving the module.
        $pid = $this->currentPropertyId();
        if ($this->filterBranch === 'hq') {
            $q->whereNull('property_id');
        } elseif ($this->filterBranch === 'all') {
            // No property filter.
        } elseif ($pid !== null) {
            // Default: current branch + HQ (null property).
            $q->where(fn ($qq) => $qq->where('property_id', $pid)->orWhereNull('property_id'));
        }

        if ($this->filterFrom !== '') {
            $q->whereDate('incurred_on', '>=', $this->filterFrom);
        }
        if ($this->filterTo !== '') {
            $q->whereDate('incurred_on', '<=', $this->filterTo);
        }
        if ($this->filterCategoryId > 0) {
            $q->where('expense_category_id', $this->filterCategoryId);
        }
        if ($this->filterPaidVia !== '') {
            $q->where('paid_via', $this->filterPaidVia);
        }

        return $q;
    }

    public function render(): View
    {
        $tenantId = (int) \App\Models\Tenant::current()?->id;
        $categories = ExpenseCategory::query()->orderBy('name')->get();

        $query = $this->baseQuery()->with(['category:id,name,color', 'property:id,name']);
        $expenses = $query->orderByDesc('incurred_on')->orderByDesc('id')->limit(200)->get();

        // Insight strip — this month total, previous month total,
        // top category this month, cumulative average per week.
        $now = Carbon::now();
        $thisMonthFrom = $now->copy()->startOfMonth();
        $thisMonthTo   = $now->copy()->endOfMonth();
        $lastMonthFrom = $now->copy()->subMonth()->startOfMonth();
        $lastMonthTo   = $now->copy()->subMonth()->endOfMonth();

        $insightBase = fn () => Expense::query()->where('tenant_id', $tenantId);
        $branchScope = function ($q) {
            $pid = $this->currentPropertyId();
            if ($this->filterBranch === 'hq') return $q->whereNull('property_id');
            if ($this->filterBranch === 'all') return $q;
            return $pid ? $q->where(fn ($qq) => $qq->where('property_id', $pid)->orWhereNull('property_id')) : $q;
        };

        $thisMonthTotal = (int) $branchScope($insightBase())
            ->whereBetween('incurred_on', [$thisMonthFrom, $thisMonthTo])
            ->sum('amount');
        $lastMonthTotal = (int) $branchScope($insightBase())
            ->whereBetween('incurred_on', [$lastMonthFrom, $lastMonthTo])
            ->sum('amount');

        $topCategoryRow = $branchScope($insightBase())
            ->whereBetween('incurred_on', [$thisMonthFrom, $thisMonthTo])
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->groupBy('expense_category_id')
            ->orderByDesc('total')
            ->first();
        $topCategory = $topCategoryRow
            ? $categories->firstWhere('id', (int) $topCategoryRow->expense_category_id)
            : null;
        $topCategoryTotal = $topCategoryRow ? (int) $topCategoryRow->total : 0;

        // Per-category summary for the sidebar list — this month.
        $perCategoryThisMonth = $branchScope($insightBase())
            ->whereBetween('incurred_on', [$thisMonthFrom, $thisMonthTo])
            ->selectRaw('expense_category_id, SUM(amount) as total, COUNT(id) as count')
            ->groupBy('expense_category_id')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'category' => $categories->firstWhere('id', (int) $r->expense_category_id),
                'total' => (int) $r->total,
                'count' => (int) $r->count,
            ])
            ->filter(fn ($r) => $r['category'] !== null)
            ->values();

        // Total for the active filter set (drives the "showing X of ₦Y" summary).
        $filteredTotal = (int) $this->baseQuery()->sum('amount');

        return view('livewire.dashboard.accounting.expenses.index', [
            'categories' => $categories,
            'expenses' => $expenses,
            'insights' => [
                'this_month_total' => $thisMonthTotal,
                'last_month_total' => $lastMonthTotal,
                'top_category' => $topCategory,
                'top_category_total' => $topCategoryTotal,
                'per_category' => $perCategoryThisMonth,
            ],
            'filteredTotal' => $filteredTotal,
        ]);
    }
}
