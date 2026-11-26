<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Rooms;

use App\Enums\BookingStatus;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomInspection;
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
        $states = $this->roomStates();

        return view('livewire.dashboard.rooms.index', [
            'rooms' => $this->rooms(),
            'roomStates' => $states,
            'roomSummary' => collect($states)->countBy('label')->all(),
            'statuses' => RoomStatus::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Room>
     */
    private function rooms(): LengthAwarePaginator
    {
        $query = Room::query()
            ->with(['category'])
            ->orderByDisplayName();

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

    /**
     * What's happening in each of the branch's rooms right now, keyed by
     * room id: covers the whole branch, not just this page, so the summary
     * above the table counts every room.
     *
     * @return array<int, array{label: string, class: string, guest: ?string, next: ?string}>
     */
    private function roomStates(): array
    {
        $rooms = Room::query();
        $currentProperty = app(PropertyContext::class)->current();
        if ($currentProperty !== null) {
            $rooms->where('property_id', $currentProperty->getKey());
        }
        $rooms = $rooms->get(['id', 'status']);
        $ids = $rooms->modelKeys();

        $inHouse = Booking::query()
            ->whereIn('room_id', $ids)
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->pluck('guest_name', 'room_id');

        $arrivals = Booking::query()
            ->whereIn('room_id', $ids)
            ->whereIn('booking_status', [BookingStatus::Confirmed->value, BookingStatus::OnHold->value])
            ->whereDate('checkin_date', '>=', today())
            ->orderByDesc('checkin_date')
            ->get(['room_id', 'guest_name', 'checkin_date'])
            ->keyBy('room_id');

        $inspections = RoomInspection::query()
            ->whereIn('room_id', $ids)
            ->where('status', '!=', RoomInspectionStatus::Passed->value)
            ->pluck('status', 'room_id');

        return $rooms->mapWithKeys(function (Room $room) use ($inHouse, $arrivals, $inspections): array {
            $guest = $inHouse->get($room->id);
            $arrival = $arrivals->get($room->id);
            $inspection = $inspections->get($room->id);
            $arrivesToday = $arrival?->checkin_date->isToday() ?? false;

            [$label, $class] = match (true) {
                $room->status !== RoomStatus::Active => ['Out of service', 'lp-tag--cancelled'],
                $guest !== null => ['Occupied', 'lp-tag--checked-in'],
                in_array($inspection, [RoomInspectionStatus::CleaningDone, RoomInspectionStatus::PorterVerified], true) => ['Needs inspection', 'lp-tag--confirmed'],
                $inspection !== null => ['Needs cleaning', 'lp-tag--on-hold'],
                default => ['Ready', 'lp-tag--ready'],
            };

            return [$room->id => [
                'label' => $label,
                'class' => $class,
                'guest' => $guest ?? ($arrivesToday ? $arrival->guest_name.' arriving' : null),
                'next' => $arrival === null ? null : ($arrivesToday ? 'Today' : $arrival->checkin_date->format('D j M')),
            ]];
        })->all();
    }
}
