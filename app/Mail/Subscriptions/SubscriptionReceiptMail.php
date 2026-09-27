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
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Receipt for the amount an admin recorded against a subscription
 * activation / renewal. Fires only when amount_received > 0.
 */
class SubscriptionReceiptMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public int $amount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received: ₦'.number_format($this->amount).' · Keverna',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.subscriptions.receipt',
            with: [
                'subscription' => $this->subscription,
                'amount'       => $this->amount,
            ],
        );
    }
}
