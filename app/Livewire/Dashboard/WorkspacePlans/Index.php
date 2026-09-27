<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\WorkspacePlans;

use App\Enums\BillingInterval;
use App\Models\WorkspacePlan;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'interval')]
    public string $intervalFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingIntervalFilter(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $id): void
    {
        $p = WorkspacePlan::query()->find($id);
        if ($p === null) {
            return;
        }
        $p->is_active = ! $p->is_active;
        $p->save();
    }

    public function delete(int $id): void
    {
        WorkspacePlan::query()->where('id', $id)->delete();
        session()->flash('status', 'Plan removed.');
    }

    public function render(): View
    {
        return view('livewire.dashboard.workspace-plans.index', [
            'plans'     => $this->plans(),
            'intervals' => BillingInterval::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, WorkspacePlan>
     */
    private function plans(): LengthAwarePaginator
    {
        $q = WorkspacePlan::query()->with('workspace')->latest('id');
        if ($this->search !== '') {
            $q->where('name', 'like', '%'.$this->search.'%');
        }
        if ($this->intervalFilter !== '') {
            $q->where('billing_interval', $this->intervalFilter);
        }

        return $q->paginate(15);
    }
}
