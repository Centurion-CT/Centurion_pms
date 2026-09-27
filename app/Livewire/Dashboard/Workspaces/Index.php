<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Workspaces;

use App\Enums\DeskBookingStatus;
use App\Enums\WorkspaceType;
use App\Models\DeskBooking;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator-facing workspace inventory list. Search + type filter.
 * Tenant scope handled by Workspace's BelongsToTenant trait.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $id): void
    {
        $w = Workspace::query()->find($id);
        if ($w === null) {
            return;
        }
        $w->is_active = ! $w->is_active;
        $w->save();
    }

    public function delete(int $id): void
    {
        $w = Workspace::query()->find($id);
        $w?->delete();
        session()->flash('status', 'Workspace archived.');
    }

    public function render(): View
    {
        $workspaces = $this->workspaces();

        return view('livewire.dashboard.workspaces.index', [
            'workspaces'   => $workspaces,
            'types'        => WorkspaceType::cases(),
            'occupancy'    => $this->todayOccupancy($workspaces->pluck('id')->all()),
            'ganttWindow'  => $this->ganttWindow(),
        ]);
    }

    /**
     * @return array{start_hour: int, end_hour: int, hours: array<int>}
     */
    private function ganttWindow(): array
    {
        // Business day for the Gantt strip: 07:00 → 22:00 (16 hourly
        // cells). Wide enough to fit early breakfast / late-evening
        // meeting rooms, narrow enough to keep individual cells
        // readable on desktop widths.
        $start = 7;
        $end   = 22;
        return [
            'start_hour' => $start,
            'end_hour'   => $end,
            'hours'      => range($start, $end - 1),
        ];
    }

    /**
     * Per-workspace booked-hour map for TODAY. Runs one query per page
     * load (whereIn on the visible workspace ids), returns an array
     * shaped so the blade can paint a hourly Gantt strip with zero
     * additional work per cell.
     *
     *   $occupancy[workspace_id] = [
     *     'bookings' => Collection<DeskBooking>,      // today's confirmed
     *     'next_free' => Carbon|null,                  // when the current occupant leaves
     *     'next_booked' => Carbon|null,                // when the next occupant arrives
     *     'occupied_now' => bool,
     *     'total_hours_today' => int,                  // for a quick "N/16 hrs" chip
     *   ]
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{
     *   bookings: \Illuminate\Support\Collection<int, DeskBooking>,
     *   next_free: Carbon|null,
     *   next_booked: Carbon|null,
     *   occupied_now: bool,
     *   total_hours_today: int,
     * }>
     */
    private function todayOccupancy(array $ids): array
    {
        if ($ids === []) return [];

        $dayStart = Carbon::today();
        $dayEnd   = Carbon::today()->endOfDay();
        $now      = Carbon::now();

        $bookings = DeskBooking::query()
            ->whereIn('workspace_id', $ids)
            ->whereIn('status', [
                DeskBookingStatus::Pending->value,
                DeskBookingStatus::Confirmed->value,
                DeskBookingStatus::InUse->value,
            ])
            ->where('start_at', '<=', $dayEnd)
            ->where('end_at', '>=', $dayStart)
            ->orderBy('start_at')
            ->get()
            ->groupBy('workspace_id');

        $out = [];
        foreach ($ids as $wid) {
            $rows = $bookings->get($wid) ?? collect();
            $totalHoursToday = 0;
            $occupiedNow = false;
            $nextFree = null;
            $nextBooked = null;

            foreach ($rows as $b) {
                // Clip the booking span to today so a multi-day booking
                // doesn't inflate today's utilisation number.
                $spanStart = $b->start_at->greaterThan($dayStart) ? $b->start_at : $dayStart;
                $spanEnd   = $b->end_at->lessThan($dayEnd)         ? $b->end_at   : $dayEnd;
                $hours = max(0, $spanStart->floatDiffInHours($spanEnd));
                $totalHoursToday += (int) round($hours);

                if ($now->betweenIncluded($spanStart, $spanEnd)) {
                    $occupiedNow = true;
                    if ($nextFree === null || $spanEnd->lessThan($nextFree)) {
                        $nextFree = $spanEnd->copy();
                    }
                }
                if ($spanStart->greaterThan($now)) {
                    if ($nextBooked === null || $spanStart->lessThan($nextBooked)) {
                        $nextBooked = $spanStart->copy();
                    }
                }
            }

            $out[$wid] = [
                'bookings'          => $rows,
                'next_free'         => $nextFree,
                'next_booked'       => $nextBooked,
                'occupied_now'      => $occupiedNow,
                'total_hours_today' => $totalHoursToday,
            ];
        }
        return $out;
    }

    /**
     * @return LengthAwarePaginator<int, Workspace>
     */
    private function workspaces(): LengthAwarePaginator
    {
        $q = Workspace::query()->latest('id');
        if ($this->search !== '') {
            $q->where('name', 'like', '%'.$this->search.'%');
        }
        if ($this->typeFilter !== '') {
            $q->where('type', $this->typeFilter);
        }

        return $q->paginate(15);
    }
}
