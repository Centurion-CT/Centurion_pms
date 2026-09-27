<?php

declare(strict_types=1);

namespace App\Mail\Subscriptions;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Renewal reminder (PRD §4.2). Sent at 30, 14, 7, and 1 day before
 * subscription expiry; the milestone is stamped on the subscription row to
 * keep SendRenewalReminders idempotent.
 */
class RenewalReminderMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public int $daysBefore,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectForMilestone(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.subscriptions.renewal-reminder',
            with: [
                'subscription' => $this->subscription,
                'tenant' => $this->subscription->tenant,
                'daysBefore' => $this->daysBefore,
                'expiresAt' => $this->subscription->expires_at,
            ],
        );
    }

    private function subjectForMilestone(): string
    {
        return match (true) {
            $this->daysBefore === 1 => 'Your Keverna subscription expires tomorrow',
            $this->daysBefore === 7 => 'Your Keverna subscription expires in a week',
            default => "Your Keverna subscription expires in {$this->daysBefore} days",
        };
    }
}
