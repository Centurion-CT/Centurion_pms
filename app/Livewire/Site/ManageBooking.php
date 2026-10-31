<?php

declare(strict_types=1);

namespace App\Livewire\Site;

use App\Models\Booking;
use App\Services\Bookings\GuestPortalTokenService;
use App\Site\BookingSite;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Manage booking" on the booking site: a guest gives their booking
 * reference and the email or phone they booked with, and is sent to
 * the existing guest booking page (cancel, pay the balance, change
 * dates) with a fresh access link. Limited to 5 tries per 10 minutes
 * per visitor, and it never says which half didn't match.
 */
#[Layout('site.layouts.app')]
class ManageBooking extends Component
{
    public string $ref = '';

    public string $contact = '';

    public function find(GuestPortalTokenService $tokens): void
    {
        $this->validate([
            'ref' => ['required', 'string', 'max:40'],
            'contact' => ['required', 'string', 'max:190'],
        ], [], ['ref' => 'booking reference', 'contact' => 'email or phone']);

        $key = 'manage-booking:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('ref', 'Too many tries. Wait a few minutes, or call the front desk.');

            return;
        }
        RateLimiter::hit($key, 600);

        $booking = Booking::query()->where('booking_ref', strtoupper(trim($this->ref)))->first();
        if ($booking === null || ! $this->contactMatches($booking)) {
            $this->addError('ref', "We couldn't find a booking with that reference and email or phone. Check both, or call the front desk.");

            return;
        }

        $issued = $tokens->issue($booking);
        $this->redirect(route('tenant.guest.show', ['bookingRef' => $booking->booking_ref, 'token' => $issued['plaintext']]));
    }

    public function render(BookingSite $site): View
    {
        return view('livewire.site.manage-booking')
            ->title('Manage your booking · '.$site->business()->name)
            ->layoutData(['pageClass' => 'pg-manage']);
    }

    private function contactMatches(Booking $booking): bool
    {
        $given = trim($this->contact);
        if (str_contains($given, '@')) {
            return strcasecmp($given, (string) $booking->guest_email) === 0;
        }

        $digits = fn (string $s) => substr((string) preg_replace('/\D+/', '', $s), -9);

        return strlen($digits($given)) === 9 && $digits($given) === $digits((string) $booking->guest_phone);
    }
}
