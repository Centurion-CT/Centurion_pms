<?php

declare(strict_types=1);

namespace App\Livewire\Guest;

use App\Actions\Bookings\InitiateBalancePayment;
use App\Livewire\Guest\Concerns\ResolvesPortalBooking;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

/**
 * "Pay outstanding balance" entry point in the guest portal (PRD §6.4.4).
 * Confirms the amount, then redirects to Paystack via {@see InitiateBalancePayment}.
 * The success webhook ({@see ConfirmBookingPayment}) flips the booking back
 * to Confirmed and clears the balance, same path the §12 cron-triggered
 * reminder uses.
 */
class PayBalance extends Component
{
    use ResolvesPortalBooking;

    public function mount(string $bookingRef, GuestPortalTokenService $tokens): void
    {
        $this->mountPortalBooking($bookingRef, (string) request()->query('token', ''), $tokens);
    }

    public function pay(GuestPortalTokenService $tokens, InitiateBalancePayment $init): void
    {
        $booking = $this->loadBooking($tokens);

        try {
            $checkoutUrl = $init->handle($booking);
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t pay', message: $e->getMessage(), kind: 'err');

            return;
        }

        $this->redirect($checkoutUrl);
    }

    public function render(GuestPortalTokenService $tokens): View
    {
        $booking = $this->loadBooking($tokens);

        return view('livewire.guest.pay-balance', [
            'booking' => $booking,
        ])->layout('layouts.tenant-public');
    }
}
