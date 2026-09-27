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
 * Check-in reminder sent 24 hours before the booking's checkin date
 * (PRD §7.1).
 */
class CheckInReminderMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use BindsBookingTenant;
    use Queueable;
    use RendersTemplatedBody;
    use SerializesModels;

    public ?string $portalUrl = null;

    public function __construct(public Booking $booking) {}

    protected function templateKey(): EmailTemplateKey
    {
        return EmailTemplateKey::CheckinReminder;
    }

    public function envelope(): Envelope { return $this->templatedEnvelope(); }
    public function content(): Content   { return $this->templatedContent(); }
}
