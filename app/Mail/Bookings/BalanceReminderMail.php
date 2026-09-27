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
 * Reminds the guest that their outstanding balance is due (PRD §6.4.3 / §7.1).
 * Sent at 7 / 3 / 1 days before `balance_due_at` by SendBalanceReminders.
 */
class BalanceReminderMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use BindsBookingTenant;
    use Queueable;
    use RendersTemplatedBody;
    use SerializesModels;

    public ?string $portalUrl = null;

    public function __construct(
        public Booking $booking,
        public int $daysBefore,
    ) {}

    protected function templateKey(): EmailTemplateKey
    {
        return EmailTemplateKey::BalanceReminder;
    }

    public function envelope(): Envelope { return $this->templatedEnvelope(); }
    public function content(): Content   { return $this->templatedContent(); }
}
