<?php

declare(strict_types=1);

namespace App\Livewire\Guest;

use App\Actions\Reviews\SubmitGuestReview;
use App\Enums\BookingStatus;
use App\Livewire\Guest\Concerns\ResolvesPortalBooking;
use App\Models\Booking;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;

/**
 * Guest review submission form (post-§19). Reachable via the same
 * portal-token pattern as the other guest pages, `?token=` query string,
 * `ResolvesPortalBooking` trait handles auth + tenant scoping.
 *
 * The form is gated on `booking_status === CheckedOut`; before that we
 * render an informational state telling the guest when reviews unlock.
 */
class Review extends Component
{
    use ResolvesPortalBooking;

    #[Validate('required|integer|min:1|max:5')]
    public int $rating = 5;

    #[Validate('required|string|min:10|max:2000')]
    public string $body = '';

    public bool $submitted = false;

    public function mount(string $bookingRef, GuestPortalTokenService $tokens): void
    {
        $this->mountPortalBooking($bookingRef, (string) request()->query('token', ''), $tokens);
    }

    public function submit(GuestPortalTokenService $tokens, SubmitGuestReview $action): void
    {
        $this->validate();
        $booking = $this->loadBooking($tokens);

        try {
            $action->handle($booking, $this->rating, $this->body);
            $this->submitted = true;
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t submit', message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(GuestPortalTokenService $tokens): View
    {
        $booking = $this->loadBooking($tokens);

        return view('livewire.guest.review', [
            'booking' => $booking,
            'eligible' => $this->eligible($booking),
            'existingReview' => $booking->review,
        ])->layout('layouts.tenant-public');
    }

    private function eligible(Booking $booking): bool
    {
        return $booking->booking_status === BookingStatus::CheckedOut;
    }
}
