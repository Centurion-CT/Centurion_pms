<?php

declare(strict_types=1);

namespace App\Livewire\Guest;

use App\Actions\Bookings\CancelBooking;
use App\Enums\BookingStatus;
use App\Livewire\Guest\Concerns\ResolvesPortalBooking;
use App\Models\Booking;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;
use RuntimeException;

/**
 * Confirm-and-cancel page in the guest portal (PRD §6.4.4). Two states:
 * confirm prompt + post-cancellation summary. Showing the deposit-forfeit
 * outcome before the click is critical, guests inside the 7-day window
 * deserve to know the deposit doesn't come back.
 */
class Cancel extends Component
{
    use ResolvesPortalBooking;

    public bool $cancelled = false;

    public bool $resultDepositForfeit = false;

    public function mount(string $bookingRef, GuestPortalTokenService $tokens): void
    {
        $this->mountPortalBooking($bookingRef, (string) request()->query('token', ''), $tokens);
    }

    public function confirm(GuestPortalTokenService $tokens, CancelBooking $cancel): void
    {
        $booking = $this->loadBooking($tokens);

        try {
            $result = $cancel->handle($booking);
            $this->cancelled = true;
            $this->resultDepositForfeit = $result->depositForfeit;
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: "Couldn't cancel", message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(GuestPortalTokenService $tokens): View
    {
        $booking = $this->loadBooking($tokens);

        return view('livewire.guest.cancel', [
            'booking' => $booking,
            'isAlreadyCancelled' => ! $booking->booking_status->holdsInventory(),
            'depositForfeitPreview' => $this->depositForfeitPreview($booking),
        ])->layout('layouts.tenant-public');
    }

    private function depositForfeitPreview(Booking $booking): bool
    {
        if ($booking->deposit_amount <= 0) {
            return false;
        }
        if ($booking->booking_status === BookingStatus::Cancelled) {
            return false;
        }

        $checkin = $booking->checkin_date->copy()->startOfDay();
        $daysOut = (int) Carbon::today()->diffInDays($checkin, absolute: false);

        return $daysOut < 7;
    }
}
