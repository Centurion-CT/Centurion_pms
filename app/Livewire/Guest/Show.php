<?php

declare(strict_types=1);

namespace App\Livewire\Guest;

use App\Actions\Bookings\PayBookingFromWallet;
use App\Livewire\Guest\Concerns\ResolvesPortalBooking;
use App\Models\Booking;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Guest portal landing (PRD §6.4.4). Displays booking summary + the entry
 * links to the three actions (cancel, pay balance, request change).
 *
 * The token is in the query string and is passed through to action URLs so
 * the guest never has to re-authenticate within a single email-link session.
 */
class Show extends Component
{
    use ResolvesPortalBooking;

    public function mount(string $bookingRef, GuestPortalTokenService $tokens): void
    {
        $this->mountPortalBooking($bookingRef, (string) request()->query('token', ''), $tokens);
    }

    /**
     * Guest-initiated "pay this booking from my wallet". Uses the same
     * PayBookingFromWallet action the operator uses (with operator=null
     * so the ledger + activity log attribute the spend to the guest).
     */
    public function payFromWallet(GuestPortalTokenService $tokens, PayBookingFromWallet $action): void
    {
        $booking = $this->loadBooking($tokens);
        try {
            $payment = $action->handle($booking, operator: null);
            $this->dispatch('notify',
                title: 'Done',
                message: $payment === null
                    ? 'Nothing to pay, your booking is already settled.'
                    : 'Paid ₦'.number_format((int) $payment->amount).' from your wallet.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(GuestPortalTokenService $tokens): View
    {
        $booking = $this->loadBooking($tokens);
        $customer = $booking->customer;
        $walletBalance = $customer?->walletBalance() ?? 0;

        // Recent wallet activity, last 5 rows so the guest can see how
        // their balance was built up and spent without exposing operator
        // metadata (approver names, admin adjustments).
        $recentWalletTx = $customer !== null
            ? $customer->walletTransactions()->limit(5)->get()
            : collect();

        return view('livewire.guest.show', [
            'booking' => $booking,
            'canPayBalance' => $this->canPayBalance($booking),
            'canReview' => $this->canReview($booking),
            'hasReview' => $booking->review()->exists(),
            'walletBalance' => (int) $walletBalance,
            'walletCanCoverOutstanding' => $walletBalance > 0 && (int) $booking->outstandingTotal() > 0
                && $walletBalance >= (int) $booking->outstandingTotal(),
            'recentWalletTx' => $recentWalletTx,
        ])->layout('layouts.tenant-public');
    }

    private function canPayBalance(Booking $booking): bool
    {
        return $booking->booking_status->value === 'on_hold' && $booking->balance_due > 0;
    }

    private function canReview(Booking $booking): bool
    {
        return $booking->booking_status->value === 'checked_out';
    }
}
