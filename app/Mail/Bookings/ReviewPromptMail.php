<?php

declare(strict_types=1);

namespace App\Mail\Bookings;

use App\Console\Commands\Bookings\SendReviewPrompts;
use App\Mail\Bookings\Concerns\BindsBookingTenant;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Sent 24 hours after check-out — "how was your stay?" prompt with a
 * deep link into the guest portal review form (PRD post-§19).
 *
 * Driven by {@see SendReviewPrompts}.
 */
class ReviewPromptMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use BindsBookingTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
        public ?string $reviewUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "How was your stay at {$this->booking->room->name}?");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.bookings.review-prompt', with: [
            'booking' => $this->booking,
            'reviewUrl' => $this->reviewUrl,
        ]);
    }
}
