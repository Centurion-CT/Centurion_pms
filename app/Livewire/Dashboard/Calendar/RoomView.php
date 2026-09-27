<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Calendar;

use App\Models\Room;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Single-room calendar (PRD §6.2.3). Lets the operator bulk-block or
 * bulk-unblock a date range with an optional reason note. The Alpine
 * front-end exposes a drag-to-select interaction; the server-side action
 * works on a plain {start, end} pair so tests bypass the JS entirely.
 *
 * Route-model-bound `{room}`, BelongsToTenant 404s foreign-tenant ids.
 */
#[Layout('layouts.tenant-auth')]
class RoomView extends Component
{
    #[Locked]
    public int $roomId;

    #[Url(as: 'm')]
    public string $month = '';

    #[Validate('required|date')]
    public string $block_start = '';

    #[Validate('required|date|after_or_equal:block_start')]
    public string $block_end = '';

    #[Validate('nullable|string|max:120')]
    public string $block_reason = '';

    public function mount(Room $room): void
    {
        $this->roomId = $room->id;
        if ($this->month === '') {
            $this->month = Carbon::today()->format('Y-m');
        }
    }

    public function previousMonth(): void
    {
        $this->month = Carbon::parse($this->month.'-01')
            ->startOfMonth()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::parse($this->month.'-01')
            ->startOfMonth()->addMonth()->format('Y-m');
    }

    public function block(AvailabilityService $svc): void
    {
        $this->validateOnly('block_start');
        $this->validateOnly('block_end');
        $this->validateOnly('block_reason');

        $start = Carbon::parse($this->block_start);
        // Drag-select uses an INCLUSIVE end date in the UI; the service uses
        // a half-open range, so we add a day before calling.
        $end = Carbon::parse($this->block_end)->addDay();

        try {
            $svc->blockRange(
                $this->room(), $start, $end,
                $this->block_reason !== '' ? $this->block_reason : null,
            );
        } catch (RangeNotAvailableException) {
            throw ValidationException::withMessages([
                'block_start' => __('One or more dates in that range are already booked or blocked.'),
            ]);
        }

        $this->reset('block_start', 'block_end', 'block_reason');
        session()->flash('status', __('Dates blocked.'));
    }

    public function unblock(AvailabilityService $svc): void
    {
        $this->validateOnly('block_start');
        $this->validateOnly('block_end');

        $start = Carbon::parse($this->block_start);
        $end = Carbon::parse($this->block_end)->addDay();

        $deleted = $svc->unblockRange($this->room(), $start, $end);

        $this->reset('block_start', 'block_end');
        session()->flash('status', trans_choice(
            ':count date unblocked|:count dates unblocked',
            $deleted,
            ['count' => $deleted],
        ));
    }

    public function render(AvailabilityService $svc): View
    {
        $room = $this->room();
        $anchor = Carbon::parse($this->month.'-01')->startOfMonth();
        $daysInMonth = $anchor->daysInMonth;

        $cells = array_fill(1, $daysInMonth, null);
        foreach ($svc->getBlocksForMonth($room, $anchor) as $b) {
            $cells[(int) $b->date->day] = $b;
        }

        return view('livewire.dashboard.calendar.room-view', [
            'room' => $room,
            'anchor' => $anchor,
            'daysInMonth' => $daysInMonth,
            'cells' => $cells,
        ]);
    }

    private function room(): Room
    {
        return Room::query()->findOrFail($this->roomId);
    }
}
