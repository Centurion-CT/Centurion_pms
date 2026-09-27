<?php

declare(strict_types=1);

namespace App\Mail\Marketplace;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Sent to a guest after checkout on a Keverna Stays marketplace
 * booking. One CTA: a signed link to the review form at
 * /stays/reviews/{booking_ref}?signature=...&expires=... - 90-day
 * TTL is plenty for the "I meant to review that trip weeks ago"
 * long tail.
 *
 * `reviewUrl` is pre-built by the dispatcher (MarkCheckedOut) so
 * the queue worker doesn't need tenant/URL context to reconstruct it.
 */
class StaysReviewInvite extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $propertyName,
        public string $reviewUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'How was your stay at '.$this->propertyName.'?',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.marketplace.stays-review-invite', with: [
            'booking' => $this->booking,
            'propertyName' => $this->propertyName,
            'reviewUrl' => $this->reviewUrl,
        ]);
    }
}
