<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\DeskBookings;

use App\Enums\DeskBookingStatus;
use App\Models\DeskBooking;
use App\Services\Corporates\CorporateBookingService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'when')]
    public string $whenFilter = 'upcoming';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingWhenFilter(): void
    {
        $this->resetPage();
    }

    public function mark(int $id, string $to, CorporateBookingService $corporates): void
    {
        $booking = DeskBooking::query()->find($id);
        if ($booking === null) {
            return;
        }
        $status = DeskBookingStatus::tryFrom($to);
        if ($status === null) {
            return;
        }

        $prev = $booking->status;
        $booking->status = $status;
        $booking->save();

        // Corporate wallet lifecycle. Capture on completion / in-use
        // (first capture wins, idempotent). Release-or-refund on cancel.
        if ($booking->corporate_id !== null) {
            $captureStates = [DeskBookingStatus::Completed, DeskBookingStatus::InUse];
            if (in_array($status, $captureStates, true) && ! in_array($prev, $captureStates, true)) {
                $corporates->captureForDeskBooking($booking->refresh());
            } elseif ($status === DeskBookingStatus::Cancelled || $status === DeskBookingStatus::NoShow) {
                $released = $corporates->releaseForDeskBooking($booking->refresh());
                if ($released === null) {
                    $corporates->refundForDeskBooking($booking->refresh());
                }
            }
        }

        session()->flash('status', 'Booking marked '.$status->label().'.');
    }

    public function render(): View
    {
        return view('livewire.dashboard.desk-bookings.index', [
            'bookings' => $this->bookings(),
            'statuses' => DeskBookingStatus::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, DeskBooking>
     */
    private function bookings(): LengthAwarePaginator
    {
        $q = DeskBooking::query()->with(['workspace', 'customer'])->latest('start_at');

        if ($this->whenFilter === 'upcoming') {
            $q->where('end_at', '>=', now());
        } elseif ($this->whenFilter === 'past') {
            $q->where('end_at', '<', now());
        }

        if ($this->search !== '') {
            $term = $this->search;
            $q->where(function ($qq) use ($term): void {
                $qq->where('reference', 'like', '%'.$term.'%')
                    ->orWhereHas('customer', function ($cq) use ($term): void {
                        $cq->where('name', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%')
                            ->orWhere('phone', 'like', '%'.$term.'%');
                    });
            });
        }

        if ($this->statusFilter !== '') {
            $q->where('status', $this->statusFilter);
        }

        return $q->paginate(15);
    }
}
