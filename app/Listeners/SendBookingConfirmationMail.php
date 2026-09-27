<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\BookingConfirmed;
use App\Mail\Bookings\BookingConfirmedMail;
use App\Mail\Bookings\BookingOnHoldMail;
use App\Services\Bookings\GuestPortalTokenService;
use App\Services\GuestPortal\GuestNotifier;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the right mailable to the guest AND a copy to the operator
 * (tenant.email — the contact captured at registration). One listener
 * picks the template based on whether the booking is Confirmed (full pay)
 * or OnHold (deposit). PRD §7.1 + §13.
 *
 * A fresh guest-portal token is minted alongside each mail so the guest's
 * email contains a working "Manage booking" URL. The plaintext is passed
 * through to the mailable — it is never persisted in plaintext form.
 */
class SendBookingConfirmationMail
{
    public function __construct(
        private readonly GuestPortalTokenService $tokens,
        private readonly GuestNotifier $notifier,
    ) {}

    public function handle(BookingConfirmed $event): void
    {
        $booking = $event->booking;

        $issued = $this->tokens->issue($booking);
        $portalUrl = $issued['url'];

        $mail = $booking->booking_status === BookingStatus::OnHold
            ? new BookingOnHoldMail($booking, $portalUrl)
            : new BookingConfirmedMail($booking, $portalUrl);

        Mail::to($booking->guest_email)->queue($mail);

        if ($booking->tenant->email !== '') {
            Mail::to($booking->tenant->email)->queue($mail);
        }

        // Branded WhatsApp arrival card. Sent to the same portal URL
        // that lands in the email so the guest can open their booking
        // from either channel. Best-effort — sendArrivalCard() catches
        // its own errors, no need to double-guard.
        $this->notifier->sendArrivalCard($booking, $portalUrl);
    }
}
