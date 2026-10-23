<?php

declare(strict_types=1);

namespace App\Mail\Bookings;

use App\Mail\Bookings\Concerns\BindsBookingTenant;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/** Tells the guest the host kept money from a cancelled / no-show stay. */
class DepositKeptMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use BindsBookingTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
        public int $amount,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'About your payment for booking '.$this->booking->booking_ref);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.bookings.deposit-kept');
    }
}
