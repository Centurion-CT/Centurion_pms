<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\RoomCategories;

use App\Enums\RoomStatus;
use App\Models\RoomCategory;
use App\Services\Properties\PropertyContext;
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

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function delete(int $id): void
    {
        $category = RoomCategory::query()->find($id);
        if ($category === null) {
            return;
        }
        // Refuse deletion if rooms still belong to this category, the
        // operator must reassign or delete those first.
        if ($category->rooms()->exists()) {
            session()->flash('error', 'Reassign or delete the rooms in this category first.');

            return;
        }
        $category->delete();
        session()->flash('status', 'Category removed.');
    }

    public function render(): View
    {
        $query = RoomCategory::query()
            ->with('media')
            ->withCount('rooms');

        // Scope to the operator's current branch — categories carry
        // property_id, so a multi-branch org's sidebar switcher filters
        // this list to what belongs to the selected branch.
        $currentProperty = app(PropertyContext::class)->current();
        if ($currentProperty !== null) {
            $query->where('property_id', $currentProperty->getKey());
        }

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)->orWhere('type', 'like', $like);
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        $categories = $query->orderBy('name')->paginate(20);

        return view('livewire.dashboard.room-categories.index', [
            'categories' => $categories,
            'statuses' => RoomStatus::cases(),
        ]);
    }
}
