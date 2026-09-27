<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Packages;

use App\Models\Package;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';   // all / active / inactive / complimentary / paid

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function toggleActive(int $packageId): void
    {
        $package = Package::query()->find($packageId);
        if ($package === null) {
            return;
        }
        $package->active = ! $package->active;
        $package->save();
    }

    public function delete(int $packageId): void
    {
        Package::query()->where('id', $packageId)->delete();
    }

    public function render(): View
    {
        $query = Package::query();

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)->orWhere('description', 'like', $like);
            });
        }

        match ($this->statusFilter) {
            'active'         => $query->where('active', true),
            'inactive'       => $query->where('active', false),
            'complimentary'  => $query->where('is_complimentary', true),
            'paid'           => $query->where('is_complimentary', false),
            default          => null,
        };

        $packages = $query->orderByDesc('active')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.dashboard.packages.index', [
            'packages' => $packages,
            'counts' => [
                'all'           => Package::query()->count(),
                'active'        => Package::query()->where('active', true)->count(),
                'complimentary' => Package::query()->where('is_complimentary', true)->count(),
                'paid'          => Package::query()->where('is_complimentary', false)->count(),
            ],
        ]);
    }
}
