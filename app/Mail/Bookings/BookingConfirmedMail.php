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
 * Confirmation email after a full-payment booking succeeds (PRD §7.1).
 * Sent to both the guest and the operator from BookingConfirmedListener.
 *
 * Subject + body are resolved through {@see RendersTemplatedBody} so
 * the operator's customised template (if any) takes precedence over
 * the defaults stored on {@see EmailTemplateKey}.
 */
class BookingConfirmedMail extends Mailable implements NotTenantAware, ShouldQueue
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
        return EmailTemplateKey::BookingConfirmed;
    }

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope();
    }

    public function content(): Content
    {
        return $this->templatedContent();
    }
}
