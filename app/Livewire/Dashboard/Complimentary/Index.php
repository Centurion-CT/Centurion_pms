<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Complimentary;

use App\Enums\BookingStatus;
use App\Livewire\Concerns\HandlesBulkSelection;
use App\Models\Booking;
use App\Models\BookingBreakfastService;
use App\Models\Operator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Roster of guests who booked with the "complimentary breakfast" tier.
 * Kitchen uses it as the morning pull; front desk uses it to confirm
 * who's entitled at the dining room; management to gauge attach rate.
 *
 * Default view is today's covers. A date picker lets any of them
 * look ahead ("how many for tomorrow?") or back. Room-number search
 * narrows the list, front desk uses it to confirm a specific room
 * at the dining room door.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use HandlesBulkSelection;

    /** YYYY-MM-DD, the "which day's breakfast?" pointer. */
    #[Url(as: 'd')]
    public string $date = '';

    /** Room-number substring, case-insensitive. */
    #[Url(as: 'q')]
    public string $roomSearch = '';

    public function mount(): void
    {
        if ($this->date === '') {
            $this->date = Carbon::today()->toDateString();
        }
    }

    public function goToToday(): void
    {
        $this->date = Carbon::today()->toDateString();
    }

    public function clearRoomSearch(): void
    {
        $this->roomSearch = '';
    }

    /**
     * Toggle the served state for one booking on the currently viewed
     * day. Idempotent, a double-click flips serve → unserve rather
     * than duplicating rows (guarded by the unique index too).
     */
    /**
     * Bulk "Mark all served" for the currently-selected roster rows.
     * Reuses toggleServed() per booking so idempotence + the tenant
     * guard are inherited; skips rows that are already served today.
     */
    public function markSelectedServed(): void
    {
        if ($this->selectedIds === []) return;

        $day = Carbon::parse($this->date)->toDateString();
        $ids = $this->selectedIds;
        $this->clearSelection();

        $marked = 0;
        foreach ($ids as $bookingId) {
            $intId = (int) $bookingId;
            $already = BookingBreakfastService::query()
                ->where('booking_id', $intId)
                ->whereDate('service_date', $day)
                ->exists();
            if ($already) continue;
            $this->toggleServed($intId);
            $marked++;
        }
        session()->flash('status', sprintf(
            'Marked %d %s served.',
            $marked,
            $marked === 1 ? 'guest' : 'guests',
        ));
    }

    #[On('bulk-clear')]
    public function onBulkClear(): void
    {
        $this->clearSelection();
    }

    public function toggleServed(int $bookingId): void
    {
        $day = Carbon::parse($this->date)->toDateString();

        // Confirm the booking belongs to the current tenant AND still
        // carries breakfast_included, a stale row click on a
        // just-cancelled booking shouldn't create a service marker.
        $booking = Booking::query()
            ->where('breakfast_included', true)
            ->find($bookingId);
        if ($booking === null) {
            return;
        }

        $existing = BookingBreakfastService::query()
            ->where('booking_id', $bookingId)
            ->whereDate('service_date', $day)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return;
        }

        $op = auth('web')->user();
        BookingBreakfastService::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'service_date' => $day,
            'served_at' => Carbon::now(),
            'served_by_operator_id' => $op instanceof Operator ? $op->id : null,
        ]);
    }

    public function render(): View
    {
        $day = Carbon::parse($this->date);
        $dayIso = $day->toDateString();

        // Base query, every booking whose stay covers the picked day
        // AND was booked with breakfast_included. The stay window is
        // [checkin_date, checkout_date); a guest checking out on the
        // picked day does NOT get breakfast that morning (they've
        // already had it on their last full night; the check-out
        // morning is a "day of departure" grey zone). If operators
        // want it different we can flip the bound.
        $covers = Booking::query()
            ->where('breakfast_included', true)
            ->whereNotIn('booking_status', [
                BookingStatus::Cancelled->value,
                BookingStatus::CheckedOut->value,
                BookingStatus::NoShow->value,
            ])
            ->whereDate('checkin_date', '<=', $dayIso)
            ->whereDate('checkout_date', '>', $dayIso)
            ->with(['room.category', 'customer'])
            ->orderBy('room_id')
            ->get();

        if (trim($this->roomSearch) !== '') {
            $needle = mb_strtolower(trim($this->roomSearch));
            $covers = $covers->filter(fn (Booking $b) => $b->room !== null
                && str_contains(mb_strtolower((string) $b->room->room_number), $needle)
            )->values();
        }

        // Which of the visible bookings already have a service marker
        // for the viewed day. Keyed by booking_id → served_at Carbon for
        // display next to the toggle.
        $servedByBookingId = BookingBreakfastService::query()
            ->whereIn('booking_id', $covers->pluck('id')->all())
            ->whereDate('service_date', $dayIso)
            ->get()
            ->keyBy('booking_id');

        // Ambient counts, kitchen uses today+tomorrow to prep pantry
        // orders. This week = today..today+6 (rolling 7).
        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();
        $weekEnd = $today->copy()->addDays(6);

        $ambientCounts = [
            'today' => $this->coversOn($today),
            'tomorrow' => $this->coversOn($tomorrow),
            'week' => $this->coversRange($today, $weekEnd),
        ];

        return view('livewire.dashboard.complimentary.index', [
            'covers' => $covers,
            'day' => $day,
            'ambientCounts' => $ambientCounts,
            'servedByBookingId' => $servedByBookingId,
            'servedCount' => $servedByBookingId->count(),
        ]);
    }

    private function coversOn(Carbon $day): int
    {
        return (int) Booking::query()
            ->where('breakfast_included', true)
            ->whereNotIn('booking_status', [
                BookingStatus::Cancelled->value,
                BookingStatus::CheckedOut->value,
                BookingStatus::NoShow->value,
            ])
            ->whereDate('checkin_date', '<=', $day->toDateString())
            ->whereDate('checkout_date', '>', $day->toDateString())
            ->count();
    }

    private function coversRange(Carbon $from, Carbon $to): int
    {
        // Number of "breakfast covers" across the range, sums each
        // day's covers so a 3-night stay counts 3 breakfasts. Small N
        // in practice (7 days × ≤50 rooms) so per-day is fine.
        $total = 0;
        for ($c = $from->copy(); $c->lessThanOrEqualTo($to); $c->addDay()) {
            $total += $this->coversOn($c);
        }

        return $total;
    }
}
