<?php

declare(strict_types=1);

namespace App\Mail\Marketplace;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\ReplyTo;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Guest-submitted report on a Keverna Stays listing.
 * Sent to platform support; reply-to is the guest's email so a
 * moderator can hit reply and start the conversation directly.
 */
class PropertyReportSubmitted extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $propertyName,
        public string $propertySlug,
        public string $propertyUrl,
        public string $reason,
        public string $message,
        public string $reporterEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Stays report: '.$this->propertyName.' ('.$this->reason.')',
            replyTo: [new ReplyTo($this->reporterEmail)],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.marketplace.property-report-submitted', with: [
            'propertyName'   => $this->propertyName,
            'propertySlug'   => $this->propertySlug,
            'propertyUrl'    => $this->propertyUrl,
            'reason'         => $this->reason,
            'message'        => $this->message,
            'reporterEmail'  => $this->reporterEmail,
        ]);
    }
}
