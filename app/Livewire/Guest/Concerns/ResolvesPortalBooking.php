<?php

declare(strict_types=1);

namespace App\Livewire\Guest\Concerns;

use App\Models\Booking;
use App\Services\Bookings\GuestPortalTokenService;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared mount logic for every guest-portal Livewire page: resolve the
 * (bookingRef, token) pair to a real Booking or 404. Locking $bookingId and
 * $token prevents the client from swapping them after mount, the token
 * lives in the Livewire snapshot just so subsequent action calls (cancel,
 * pay balance, request change) can re-fetch the booking server-side.
 */
trait ResolvesPortalBooking
{
    #[Locked]
    public int $bookingId;

    #[Locked]
    public string $token;

    public function mountPortalBooking(string $bookingRef, string $token, GuestPortalTokenService $tokens): Booking
    {
        $booking = $tokens->resolveBooking($bookingRef, $token);
        if ($booking === null) {
            throw new NotFoundHttpException;
        }

        $this->bookingId = $booking->id;
        $this->token = $token;

        return $booking;
    }

    protected function loadBooking(GuestPortalTokenService $tokens): Booking
    {
        $booking = Booking::query()->find($this->bookingId);
        if ($booking === null) {
            throw new NotFoundHttpException;
        }

        // Re-validate the token on every server action, a paranoid second
        // pass that blocks any swap of $bookingId / $token in the snapshot.
        $resolved = $tokens->resolveBooking($booking->booking_ref, $this->token);
        if ($resolved === null || $resolved->id !== $booking->id) {
            throw new NotFoundHttpException;
        }

        return $booking;
    }
}
