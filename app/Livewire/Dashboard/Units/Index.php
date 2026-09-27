<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Units;

use App\Enums\UnitStatus;
use App\Models\Unit;
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

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $u = Unit::query()->find($id);
        $u?->delete();
        session()->flash('status', 'Unit archived.');
    }

    public function render(): View
    {
        return view('livewire.dashboard.units.index', [
            'units'    => $this->units(),
            'statuses' => UnitStatus::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Unit>
     */
    private function units(): LengthAwarePaginator
    {
        $q = Unit::query()->latest('id');
        if ($this->search !== '') {
            $q->where(function ($qq): void {
                $qq->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('address', 'like', '%'.$this->search.'%');
            });
        }
        if ($this->statusFilter !== '') {
            $q->where('status', $this->statusFilter);
        }

        return $q->paginate(15);
    }
}
