<?php

declare(strict_types=1);

namespace App\Mail\Platform;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The 6-digit code that confirms a sign-up's email (App\Platform\SignUpCode).
 * Sent straight away, not queued: the person is waiting on the code screen.
 */
class SignUpCodeMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $code,
        public string $businessName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your ".config('brand.name').' code');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform.signup-code');
    }
}
