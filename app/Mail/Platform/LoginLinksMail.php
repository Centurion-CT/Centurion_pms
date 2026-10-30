<?php

declare(strict_types=1);

namespace App\Mail\Platform;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Which business do I log in to?" Lists each business this email works at,
 * with a link to that business's own staff login. The links only open the
 * login page; nobody gets in without their password.
 */
class LoginLinksMail extends Mailable
{
    /**
     * @param  list<array{name: string, host: string, role: string, url: string}>  $businesses
     */
    public function __construct(
        public string $name,
        public array $businesses,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('brand.name').' login links');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform.login-links');
    }
}
