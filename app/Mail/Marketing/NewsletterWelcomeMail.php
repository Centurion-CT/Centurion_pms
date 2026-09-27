<?php

declare(strict_types=1);

namespace App\Mail\Marketing;

use App\Models\NewsletterSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * First-touch thank-you for landing-page newsletter subscribers.
 * Queued so the /subscribe response doesn't wait on SMTP; the
 * DB row is the source of truth so a mail failure doesn't lose
 * the subscription.
 */
class NewsletterWelcomeMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public NewsletterSubscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You are on the Keverna list. Welcome.');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.marketing.newsletter-welcome');
    }
}
