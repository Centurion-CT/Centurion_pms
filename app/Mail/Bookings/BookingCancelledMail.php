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
 * Cancellation confirmation email (PRD §7.1).
 *
 * `$depositForfeit` was used by the pre-templated body to phrase the
 * refund expectation. Post-templating it's no longer surfaced (the
 * default body uses neutral wording); operators who want a specific
 * forfeit phrasing can customise via the dashboard editor.
 */
class BookingCancelledMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use BindsBookingTenant;
    use Queueable;
    use RendersTemplatedBody;
    use SerializesModels;

    /** @var string|null portalUrl placeholder for the trait — cancellations don't carry one. */
    public ?string $portalUrl = null;

    public function __construct(
        public Booking $booking,
        public bool $depositForfeit = false,
    ) {}

    protected function templateKey(): EmailTemplateKey
    {
        return EmailTemplateKey::BookingCancelled;
    }

    public function envelope(): Envelope { return $this->templatedEnvelope(); }
    public function content(): Content   { return $this->templatedContent(); }
}
