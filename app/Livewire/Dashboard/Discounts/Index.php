<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Discounts;

use App\Models\Discount;
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
    public string $statusFilter = 'all';   // all / active / inactive / auto / coupon

    /** Same "branch / all" toggle as Customers index. */
    #[Url(as: 'scope', except: 'branch')]
    public string $propertyScope = 'branch';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedPropertyScope(): void { $this->resetPage(); }

    public function toggleActive(int $discountId): void
    {
        $discount = Discount::query()->find($discountId);
        if ($discount === null) {
            return;
        }
        $discount->active = ! $discount->active;
        $discount->save();
    }

    public function delete(int $discountId): void
    {
        Discount::query()->where('id', $discountId)->delete();
    }

    public function render(): View
    {
        $query = Discount::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty());

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)->orWhere('code', 'like', $like);
            });
        }

        match ($this->statusFilter) {
            'active'   => $query->where('active', true),
            'inactive' => $query->where('active', false),
            'auto'     => $query->whereNull('code'),
            'coupon'   => $query->whereNotNull('code'),
            default    => null,
        };

        $discounts = $query->orderByDesc('active')
            ->orderByDesc('id')
            ->paginate(20);

        $propertyCount = \App\Models\Tenant::current()?->properties()->count() ?? 0;

        return view('livewire.dashboard.discounts.index', [
            'discounts' => $discounts,
            'counts' => [
                'all'      => Discount::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->count(),
                'active'   => Discount::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->where('active', true)->count(),
                'auto'     => Discount::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->whereNull('code')->count(),
                'coupon'   => Discount::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->whereNotNull('code')->count(),
            ],
            'showScopeToggle' => $propertyCount > 1,
            'scope' => $this->propertyScope,
        ]);
    }
}
