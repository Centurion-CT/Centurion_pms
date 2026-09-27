<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Notifications;

use App\Livewire\Concerns\HandlesBulkSelection;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Full-page notifications center, sits next to the bell dropdown for
 * cases the operator wants history, filters, or bulk mark-read.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;
    use HandlesBulkSelection;

    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';   // all / unread / read

    /** Sender-lane pill filter — 'all' | 'bookings' | 'payments' | 'system' */
    #[Url(as: 'lane', except: 'all')]
    public string $laneFilter = 'all';

    public function updatedFilter(): void { $this->resetPage(); }
    public function updatedLaneFilter(): void { $this->resetPage(); }

    #[On('bulk-clear')]
    public function onBulkClear(): void
    {
        $this->clearSelection();
    }

    /**
     * Bulk mark-read for the checked notifications. Uses the same
     * ownership guard as markRead() so an operator can't touch
     * another operator's notification even by crafting a payload.
     */
    public function markSelectedRead(): void
    {
        if ($this->selectedIds === []) return;
        $ids = $this->selectedIds;
        $this->clearSelection();

        $touched = DatabaseNotification::query()
            ->whereIn('id', $ids)
            ->where('notifiable_id', auth('web')->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        session()->flash('status', sprintf(
            'Marked %d %s read.',
            $touched,
            $touched === 1 ? 'notification' : 'notifications',
        ));
    }

    /**
     * Bulk archive/delete for the checked notifications. Same
     * ownership guard as delete().
     */
    public function deleteSelected(): void
    {
        if ($this->selectedIds === []) return;
        $ids = $this->selectedIds;
        $this->clearSelection();

        $touched = DatabaseNotification::query()
            ->whereIn('id', $ids)
            ->where('notifiable_id', auth('web')->id())
            ->delete();

        session()->flash('status', sprintf(
            'Removed %d %s.',
            $touched,
            $touched === 1 ? 'notification' : 'notifications',
        ));
    }

    public function markRead(string $id): void
    {
        DatabaseNotification::query()
            ->where('id', $id)
            ->where('notifiable_id', auth('web')->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        DatabaseNotification::query()
            ->where('notifiable_id', auth('web')->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function delete(string $id): void
    {
        DatabaseNotification::query()
            ->where('id', $id)
            ->where('notifiable_id', auth('web')->id())
            ->delete();
    }

    public function render(): View
    {
        $mineQ = DatabaseNotification::query()
            ->where('notifiable_id', auth('web')->id());

        // Per-lane count so the pill row shows N-per-lane even when
        // another filter is active. Cheap SQL: 3 count queries at
        // page render, no per-row work.
        $laneCounts = [
            'all'      => (clone $mineQ)->count(),
            'bookings' => (clone $mineQ)->where('data->booking_id', '!=', null)->count(),
            'payments' => (clone $mineQ)->where('data->event', 'like', 'payment.%')->count(),
            'system'   => (clone $mineQ)
                ->where(function ($q) {
                    $q->whereNull('data->booking_id')
                      ->where(function ($q2) {
                          $q2->whereNull('data->event')
                             ->orWhere('data->event', 'not like', 'payment.%');
                      });
                })
                ->count(),
        ];

        $query = (clone $mineQ)->orderByDesc('created_at');

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filter === 'read') {
            $query->whereNotNull('read_at');
        }
        if ($this->laneFilter === 'bookings') {
            $query->whereNotNull('data->booking_id');
        } elseif ($this->laneFilter === 'payments') {
            $query->where('data->event', 'like', 'payment.%');
        } elseif ($this->laneFilter === 'system') {
            $query->whereNull('data->booking_id')
                  ->where(function ($q) {
                      $q->whereNull('data->event')
                        ->orWhere('data->event', 'not like', 'payment.%');
                  });
        }

        $notifications = $query->paginate(25);

        $counts = [
            'all'    => $laneCounts['all'],
            'unread' => (clone $mineQ)->whereNull('read_at')->count(),
        ];

        return view('livewire.dashboard.notifications.index', [
            'notifications' => $notifications,
            'counts'        => $counts,
            'laneCounts'    => $laneCounts,
        ]);
    }
}
