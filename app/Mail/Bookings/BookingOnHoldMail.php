<?php

declare(strict_types=1);

namespace App\Mail\Bookings;

use App\Enums\EmailTemplateKey;
use App\Mail\Bookings\Concerns\BindsBookingTenant;
use App\Mail\Bookings\Concerns\RendersTemplatedBody;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Hold-confirmation email after a deposit payment (PRD §7.1).
 */
class BookingOnHoldMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use BindsBookingTenant;
    use Queueable;
    use RendersTemplatedBody;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
        public ?string $portalUrl = null,
    ) {}

    protected function templateKey(): EmailTemplateKey
    {
        return EmailTemplateKey::BookingOnHold;
    }

    public function envelope(): Envelope { return $this->templatedEnvelope(); }
    public function content(): Content   { return $this->templatedContent(); }
}
