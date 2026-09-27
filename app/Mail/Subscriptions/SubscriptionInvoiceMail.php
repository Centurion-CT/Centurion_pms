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
 * Invoice for the remaining balance on a subscription. Fired ONLY
 * when contract_amount > amount_received (an outstanding remains
 * after the initial payment).
 */
class SubscriptionInvoiceMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public int $outstanding,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice: ₦'.number_format($this->outstanding).' outstanding on your Keverna subscription',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.subscriptions.invoice',
            with: [
                'subscription' => $this->subscription,
                'outstanding'  => $this->outstanding,
            ],
        );
    }
}
