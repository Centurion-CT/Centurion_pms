<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Activity;

use App\Models\Activity;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cross-tenant audit log viewer for platform admins.
 *
 * {@see Activity} has a global tenant scope that constrains operator-side
 * reads to the current tenant, here we lift it so platform staff see
 * actions across every tenant + central writes (cron, webhooks).
 *
 * Filters: causer (admin email), subject type, event verb, log channel,
 * date range, free-text search on description.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'log')]
    public string $logFilter = '';

    #[Url(as: 'event')]
    public string $eventFilter = '';

    #[Url(as: 'subject')]
    public string $subjectFilter = '';

    #[Url(as: 'from')]
    public string $fromDate = '';

    #[Url(as: 'to')]
    public string $toDate = '';

    public ?int $expandedId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLogFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    public function toggleExpanded(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'logFilter', 'eventFilter', 'subjectFilter', 'fromDate', 'toDate']);
        $this->resetPage();
    }

    public function render(): View
    {
        $rows = $this->paginate();

        return view('livewire.admin.activity.index', [
            'rows'         => $rows,
            'logOptions'   => $this->distinctValues('log_name'),
            'eventOptions' => $this->distinctValues('event'),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    private function paginate(): LengthAwarePaginator
    {
        $query = Activity::query()
            ->withoutGlobalScope('tenant')
            ->with(['causer', 'subject'])
            ->latest('id');

        $needle = trim($this->search);
        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('description', 'like', '%'.$needle.'%')
                    ->orWhere('log_name', 'like', '%'.$needle.'%')
                    ->orWhere('event', 'like', '%'.$needle.'%')
                    ->orWhere('subject_type', 'like', '%'.$needle.'%');
            });
        }

        if ($this->logFilter !== '') {
            $query->where('log_name', $this->logFilter);
        }
        if ($this->eventFilter !== '') {
            $query->where('event', $this->eventFilter);
        }
        if ($this->subjectFilter !== '') {
            $query->where('subject_type', 'like', '%'.$this->subjectFilter.'%');
        }
        if ($this->fromDate !== '') {
            $query->where('created_at', '>=', $this->fromDate.' 00:00:00');
        }
        if ($this->toDate !== '') {
            $query->where('created_at', '<=', $this->toDate.' 23:59:59');
        }

        return $query->paginate(25);
    }

    /**
     * @return list<string>
     */
    private function distinctValues(string $column): array
    {
        return Activity::query()
            ->withoutGlobalScope('tenant')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->limit(100)
            ->pluck($column)
            ->all();
    }
}
