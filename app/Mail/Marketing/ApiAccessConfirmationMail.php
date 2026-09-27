<?php

declare(strict_types=1);

namespace App\Mail\Marketing;

use App\Models\ApiAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Sent to the developer immediately after they submit the API
 * early-access form. Not tenant-aware (marketing surface, no tenant
 * yet) and queued so the request response doesn't block on SMTP.
 */
class ApiAccessConfirmationMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public ApiAccessRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You are on the Keverna API waitlist',
            replyTo: [config('mail.from.address'), config('mail.from.name')],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.marketing.api-access-confirmation');
    }
}
