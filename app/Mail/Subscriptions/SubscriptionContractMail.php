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
 * Sent to a tenant when a super-admin activates or renews their
 * subscription. Covers the agreement: plan, dates, contract value,
 * duration + any notes the admin recorded.
 */
class SubscriptionContractMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Subscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Keverna subscription is active ('.$this->subscription->plan->value.')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.subscriptions.contract',
            with: ['subscription' => $this->subscription],
        );
    }
}
