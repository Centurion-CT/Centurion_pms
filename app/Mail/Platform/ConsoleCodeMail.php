<?php

declare(strict_types=1);

namespace App\Mail\Platform;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The 6-digit code a console admin signs in with (App\Platform\ConsoleCode).
 * Sent straight away, not queued: the admin is waiting on the code screen.
 */
class ConsoleCodeMail extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your ".config('brand.name').' console code');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform.console-code');
    }
}
