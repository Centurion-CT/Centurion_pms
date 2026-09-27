<?php

declare(strict_types=1);

namespace App\Mail\Corporates;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Confirmation to a corporate-billed booker (and CC'd to their
 * corporate admin emails) — clarifies that the stay is paid from the
 * wallet, with the negotiated saving spelled out.
 *
 * Distinct from {@see \App\Mail\Bookings\BookingConfirmedMail} which
 * carries the property-operator template. This one is platform-voiced
 * so the corporate gets a consistent receipt regardless of which
 * property's template style they happened to book.
 */
class CorporateBookingConfirmedMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
    ) {}

    public function envelope(): Envelope
    {
        $ref = $this->booking->booking_ref ?? '';

        return new Envelope(
            subject: 'Booking confirmed · '.$ref,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.corporates.booking-confirmed',
            with: [
                'booking'   => $this->booking,
                'corporate' => $this->booking->corporate,
                'member'    => $this->booking->corporateMember,
                'room'      => $this->booking->room,
                'saving'    => (int) ($this->booking->corporate_discount_naira ?? 0),
            ],
        );
    }
}
