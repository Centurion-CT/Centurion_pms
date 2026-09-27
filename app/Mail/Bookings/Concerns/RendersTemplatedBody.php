<?php

declare(strict_types=1);

namespace App\Mail\Bookings\Concerns;

use App\Enums\EmailTemplateKey;
use App\Services\Email\EmailTemplateRenderer;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Shared helper for the booking-event Mailables. Each Mailable
 * declares `$booking` + `$portalUrl` and which {@see EmailTemplateKey}
 * it represents — this trait does the rest: resolve template (custom
 * or default), substitute placeholders, hand the resolved subject +
 * body to Laravel.
 *
 * Mailables stay one line in `envelope()` / `content()`; operators
 * can edit either subject or body per-tenant without touching code.
 */
trait RendersTemplatedBody
{
    abstract protected function templateKey(): EmailTemplateKey;

    protected function templatedEnvelope(): Envelope
    {
        return new Envelope(subject: $this->resolveRendered()->subject);
    }

    protected function templatedContent(): Content
    {
        $rendered = $this->resolveRendered();

        return new Content(markdown: 'mail._templated', with: [
            'subject' => $rendered->subject,
            'body' => $rendered->body,
            'portalUrl' => $this->portalUrl ?? null,
        ]);
    }

    private function resolveRendered(): \App\Services\Email\RenderedEmail
    {
        // Cache the resolution for this Mailable instance so we don't
        // hit the DB twice across envelope() + content().
        if (! isset($this->_renderedCache)) {
            /** @var EmailTemplateRenderer $renderer */
            $renderer = app(EmailTemplateRenderer::class);
            $this->_renderedCache = $renderer->renderForBooking(
                key: $this->templateKey(),
                booking: $this->booking,
                portalUrl: $this->portalUrl ?? null,
            );
        }

        return $this->_renderedCache;
    }

    /** Cache across envelope() / content() within one Mailable instance. */
    private ?\App\Services\Email\RenderedEmail $_renderedCache = null;
}
