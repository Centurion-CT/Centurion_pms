<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Inventory;

use App\Models\Operator;
use App\Models\Product;
use App\Services\Reports\InventoryTimeline;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Change history for inventory data, merges the Spatie activity_log
 * (product / outlet field changes wired via the LogsActivity trait) and
 * the stock_movements ledger (quantity changes) into a single timeline.
 *
 * The merge + normalisation lives in {@see InventoryTimeline}; this
 * component is thin: filter state → service call → paginate → render.
 * The Summary page consumes the same service with an outlet filter,
 * which is why the query logic lives out of the component.
 *
 * Visible to both Manager and InventoryClerk: a clerk needs to know what
 * happened just as much as a manager does. Tenant scoping is automatic
 * on both source queries.
 */
#[Layout('layouts.tenant-auth')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url(as: 'product', except: '')]
    public string $productFilter = '';

    #[Url(as: 'operator', except: '')]
    public string $operatorFilter = '';

    /** @var list<string> */
    #[Url(as: 'kind', except: [])]
    public array $kindFilter = [];

    #[Url(as: 'from', except: '')]
    public string $fromDate = '';

    #[Url(as: 'to', except: '')]
    public string $toDate = '';

    private const int PER_PAGE = 50;

    public function updatingProductFilter(): void
    {
        $this->resetPage();
    }

    public function updatingOperatorFilter(): void
    {
        $this->resetPage();
    }

    public function updatingKindFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFromDate(): void
    {
        $this->resetPage();
    }

    public function updatingToDate(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->productFilter = '';
        $this->operatorFilter = '';
        $this->kindFilter = [];
        $this->fromDate = '';
        $this->toDate = '';
        $this->resetPage();
    }

    public function render(InventoryTimeline $timeline): View
    {
        [$fromCarbon, $toCarbon] = $this->parseDateRange();

        $entries = $timeline->build(
            from: $fromCarbon,
            to: $toCarbon,
            productId: $this->productFilter !== '' ? (int) $this->productFilter : null,
            operatorId: $this->operatorFilter !== '' ? (int) $this->operatorFilter : null,
            kindFilter: $this->kindFilter,
        );

        $page = max(1, (int) request()->query('page', 1));
        $sliced = $entries->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values()->all();
        $paginator = new LengthAwarePaginator(
            items: $sliced,
            total: $entries->count(),
            perPage: self::PER_PAGE,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
                'pageName' => 'page',
            ],
        );

        return view('livewire.dashboard.inventory.audit-log', [
            'entries' => $paginator,
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
            'operators' => Operator::query()->orderBy('name')->get(['id', 'name']),
            'totalCount' => $entries->count(),
        ]);
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function parseDateRange(): array
    {
        $from = null;
        $to = null;
        try {
            if ($this->fromDate !== '') {
                $from = Carbon::parse($this->fromDate)->startOfDay();
            }
        } catch (Throwable) {
            // ignore malformed date, treat as no filter
        }
        try {
            if ($this->toDate !== '') {
                $to = Carbon::parse($this->toDate)->endOfDay();
            }
        } catch (Throwable) {
            // ignore
        }

        return [$from, $to];
    }
}
