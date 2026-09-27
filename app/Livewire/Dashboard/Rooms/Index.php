<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Rooms;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator-facing room list (PRD §6.3.1). Search + status filter + paginate.
 * The tenant scope is applied by Room's BelongsToTenant trait so no extra
 * scoping is needed here.
 */
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
        $room = Room::query()->find($id);
        if ($room === null) {
            return;
        }

        // PRD §6.3.1: future-booking guard lands once the bookings table
        // exists in §11. Until then a soft-delete is safe, historical Booking
        // rows (none yet) keep their room_id FK intact via SoftDeletes.
        $room->delete();

        session()->flash('status', __('Room deleted.'));
    }

    public function render(): View
    {
        return view('livewire.dashboard.rooms.index', [
            'rooms' => $this->rooms(),
            'statuses' => RoomStatus::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Room>
     */
    private function rooms(): LengthAwarePaginator
    {
        $query = Room::query()
            ->with(['category.media'])
            ->latest('id');

        // Scope to the operator's current branch — Room has property_id
        // so a multi-branch org's sidebar switcher meaningfully filters
        // this list. Falls back to unscoped for a legacy single-branch
        // tenant (context returns the first property; every room
        // matches that branch anyway).
        $currentProperty = app(PropertyContext::class)->current();
        if ($currentProperty !== null) {
            $query->where('property_id', $currentProperty->getKey());
        }

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('type', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return $query->paginate(15);
    }
}
