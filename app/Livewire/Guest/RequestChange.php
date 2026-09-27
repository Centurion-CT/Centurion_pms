<?php

declare(strict_types=1);

namespace App\Livewire\Guest;

use App\Actions\Bookings\RequestDateChange;
use App\Livewire\Guest\Concerns\ResolvesPortalBooking;
use App\Models\BookingChangeRequest;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Date-change request form in the guest portal (PRD §6.4.4). Submits a
 * pending BookingChangeRequest, the operator decides.
 */
class RequestChange extends Component
{
    use ResolvesPortalBooking;

    #[Validate('required|date|after:today')]
    public string $requested_checkin = '';

    #[Validate('required|date|after:requested_checkin')]
    public string $requested_checkout = '';

    #[Validate('nullable|string|max:500')]
    public string $guest_note = '';

    public bool $submitted = false;

    public function mount(string $bookingRef, GuestPortalTokenService $tokens): void
    {
        $booking = $this->mountPortalBooking($bookingRef, (string) request()->query('token', ''), $tokens);
        $this->requested_checkin = $booking->checkin_date->copy()->addDays(7)->toDateString();
        $this->requested_checkout = $booking->checkout_date->copy()->addDays(7)->toDateString();
    }

    public function submit(GuestPortalTokenService $tokens, RequestDateChange $action): void
    {
        $this->validate();

        $booking = $this->loadBooking($tokens);

        try {
            $action->handle(
                booking: $booking,
                requestedCheckin: Carbon::parse($this->requested_checkin),
                requestedCheckout: Carbon::parse($this->requested_checkout),
                guestNote: $this->guest_note !== '' ? $this->guest_note : null,
            );
            $this->submitted = true;
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->dispatch('notify', title: "Couldn't submit", message: $e->getMessage(), kind: 'err');
        } catch (Throwable $e) {
            $this->dispatch('notify', title: "Couldn't submit", message: 'Could not record the request. Please try again.', kind: 'err');
        }
    }

    public function render(GuestPortalTokenService $tokens): View
    {
        $booking = $this->loadBooking($tokens);

        $pending = BookingChangeRequest::query()
            ->where('booking_id', $booking->id)
            ->where('status', BookingChangeRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        return view('livewire.guest.request-change', [
            'booking' => $booking,
            'pendingRequest' => $pending,
        ])->layout('layouts.tenant-public');
    }
}
