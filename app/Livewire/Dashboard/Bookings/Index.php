<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Bookings;

use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Livewire\Concerns\HasQuickReserveForm;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Operator-facing bookings list (PRD §6.2.4 / §14).
 *
 * Filters live in URL state so an operator can bookmark a search. All
 * queries are tenant-scoped through Booking's BelongsToTenant trait, no
 * additional where-tenant_id needed.
 */
class Index extends Component
{
    use HasQuickReserveForm;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'room')]
    public ?int $roomFilter = null;

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    /**
     * 'any' (default) shows everything, 'only' filters to corporate-tagged
     * bookings, 'exclude' to walk-ins.
     */
    #[Url(as: 'corp')]
    public string $corporateFilter = '';

    /**
     * Deep-link filters used by the dashboard's "Needs attention"
     * tile. Both are string bools ('1' / '') so the URL param can
     * be shared/copied without extra parsing on this side.
     *
     * outstanding=1 → balance_due > 0, useful for reconciling money owed
     * overdue=1     → Confirmed / OnHold rows whose checkin_date has
     *                 passed but the desk hasn't checked-in or no-showed
     */
    #[Url(as: 'outstanding', except: '')]
    public string $outstandingFilter = '';

    #[Url(as: 'overdue', except: '')]
    public string $overdueFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRoomFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedCorporateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->roomFilter = null;
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->corporateFilter = '';
        $this->outstandingFilter = '';
        $this->overdueFilter = '';
        $this->resetPage();
    }

    public function updatedOutstandingFilter(): void
    {
        $this->resetPage();
    }

    public function updatedOverdueFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        // Reserve sheet needs an active + category-loaded room list;
        // filter dropdown up top wants all rooms (including inactive)
        // for historical filtering. Two separate queries; both cheap.
        $reserveRooms = Room::query()
            ->where('status', RoomStatus::Active->value)
            // base_rate + breakfast_included_rate are needed so the
            // reserve sheet's rate-tier radio pair can render.
            ->with('category:id,name,base_rate,breakfast_included_rate')
            ->orderBy('room_number')
            ->get();

        return view('livewire.dashboard.bookings.index', [
            'bookings'       => $this->paginate(),
            'rooms'          => Room::query()->orderByDisplayName()->get(),
            'statusOptions'  => BookingStatus::cases(),
            'availableRooms' => $this->availableRoomsForReserve($reserveRooms),
        ])->layout('layouts.tenant-auth');
    }

    /**
     * @return LengthAwarePaginator<int, Booking>
     */
    private function paginate(): LengthAwarePaginator
    {
        $query = Booking::query()->with(['room', 'corporate', 'roomCategory'])->latest('id');

        $needle = trim($this->search);
        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('guest_name', 'like', '%'.$needle.'%')
                    ->orWhere('guest_email', 'like', '%'.$needle.'%')
                    ->orWhere('booking_ref', 'like', '%'.$needle.'%');
            });
        }

        if ($this->statusFilter !== '' && BookingStatus::tryFrom($this->statusFilter) !== null) {
            $query->where('booking_status', $this->statusFilter);
        }

        if ($this->roomFilter !== null && $this->roomFilter > 0) {
            $query->where('room_id', $this->roomFilter);
        }

        if ($this->dateFrom !== '') {
            try {
                $from = Carbon::parse($this->dateFrom)->startOfDay();
                $query->where('checkin_date', '>=', $from);
            } catch (Throwable) {
                // ignore unparseable
            }
        }

        if ($this->dateTo !== '') {
            try {
                $to = Carbon::parse($this->dateTo)->endOfDay();
                $query->where('checkin_date', '<=', $to);
            } catch (Throwable) {
                // ignore unparseable
            }
        }

        if ($this->corporateFilter === 'only') {
            $query->whereNotNull('corporate_id');
        } elseif ($this->corporateFilter === 'exclude') {
            $query->whereNull('corporate_id');
        }

        // Money owed - deep-linked from the dashboard's "Bookings with
        // outstanding balance" attention row. Includes any live status
        // where balance still matters (excludes Cancelled/CheckedOut
        // where the row is closed).
        if ($this->outstandingFilter === '1') {
            $query->where('balance_due', '>', 0)
                ->whereNotIn('booking_status', [
                    BookingStatus::Cancelled->value,
                    BookingStatus::CheckedOut->value,
                ]);
        }

        // Overdue arrivals - deep-linked from "Check-ins to confirm or
        // mark no-show". Confirmed + OnHold rows whose checkin_date
        // has passed and no CheckIn / NoShow decision has landed yet.
        if ($this->overdueFilter === '1') {
            $query->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
            ])
                ->whereDate('checkin_date', '<', Carbon::today()->toDateString());
        }

        return $query->paginate(20);
    }
}
