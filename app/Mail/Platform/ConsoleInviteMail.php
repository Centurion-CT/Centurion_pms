<?php

declare(strict_types=1);

namespace App\Mail\Platform;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A link to set a console password: an invite for a new console user,
 * or a reset an owner sent. Sent straight away, not queued.
 */
class ConsoleInviteMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $url,
        public bool $reset = false,
        public ?string $invitedBy = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reset
            ? 'Set a new password for the '.config('brand.name').' console'
            : 'You\'re invited to the '.config('brand.name').' console');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform.console-invite');
    }
}
