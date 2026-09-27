<?php

declare(strict_types=1);

namespace App\Mail\Identity;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Magic-link claim invite (docs/unified-login.md §3, Phase 2).
 *
 * Sent by the SendGuestClaimInvite listener after a booking confirms.
 * Contains a signed URL that lets the guest claim every reservation they
 * have across every Keverna property in one place.
 *
 * `NotTenantAware`: the mail body doesn't need a bound tenant — it's a
 * central-domain flow. The originating tenant is passed as `propertyName`
 * for the "you just booked at X" line, but the mail itself queues without
 * requiring tenant context on the worker.
 */
class GuestClaimInviteMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $claimUrl,
        public string $propertyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'See all your Keverna reservations in one place');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.identity.guest-claim-invite',
            with: [
                'claimUrl' => $this->claimUrl,
                'propertyName' => $this->propertyName,
            ],
        );
    }
}
