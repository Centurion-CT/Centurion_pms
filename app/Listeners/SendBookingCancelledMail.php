<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Mail\Bookings\BookingCancelledMail;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the guest cancellation email + a copy to the operator's mailbox
 * (PRD §7.1). Operator copy is sent only if the tenant has a contact email
 * on file — same guard as the confirmation listener.
 *
 * The LISTENER runs synchronously (matches the SendBookingConfirmationMail
 * pattern from §11) — the actual mailables it dispatches are themselves
 * queued. This avoids Spatie's tenant-aware-job check tripping on a
 * standalone queued listener that has no tenantId payload.
 */
class SendBookingCancelledMail
{
    public function handle(BookingCancelled $event): void
    {
        $booking = $event->booking;
        $mail = new BookingCancelledMail($booking, $event->depositForfeit);

        Mail::to($booking->guest_email)->queue($mail);
        if ($booking->tenant->email !== '') {
            Mail::to($booking->tenant->email)->queue($mail);
        }
    }
}
