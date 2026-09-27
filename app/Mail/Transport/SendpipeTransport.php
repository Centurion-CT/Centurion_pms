<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use App\Services\Sendpipe\SendpipeClient;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Routes Laravel/Symfony Mailable sends through Sendpipe (PRD §5.1 + §7.1).
 *
 * Wiring (see App\Providers\AppServiceProvider::boot):
 *   - Mail::extend('sendpipe', fn () => new self(app(SendpipeClient::class)))
 *   - config/mail.php: 'sendpipe' mailer with 'transport' => 'sendpipe'
 *   - .env: MAIL_MAILER=sendpipe
 *
 * Anything you'd send via Mail::to(...)->queue(new SomeMailable) now goes
 * through Sendpipe transparently — call sites don't change.
 *
 * Plain-text-only mailables are supported: we forward `text` instead of `html`.
 * Attachments are intentionally NOT forwarded yet; Sendpipe accepts an
 * `attachments` array but Keverna v1 doesn't send any (PRD §7.1).
 */
class SendpipeTransport extends AbstractTransport
{
    public function __construct(private readonly SendpipeClient $client)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $raw = $message->getOriginalMessage();
        if (! $raw instanceof Message) {
            throw new RuntimeException(
                'SendpipeTransport requires Symfony\Component\Mime\Message; received '.$raw::class,
            );
        }
        $email = MessageConverter::toEmail($raw);

        $this->client->sendEmail([
            'from' => $this->primaryAddress($email->getFrom()),
            'to' => $this->addressList($email->getTo()),
            'subject' => (string) $email->getSubject(),
            'html' => $email->getHtmlBody() === null ? null : (string) $email->getHtmlBody(),
            'text' => $email->getTextBody() === null ? null : (string) $email->getTextBody(),
            'cc' => $this->addressList($email->getCc()),
            'bcc' => $this->addressList($email->getBcc()),
            'reply_to' => $this->primaryAddress($email->getReplyTo()),
        ]);
    }

    public function __toString(): string
    {
        return 'sendpipe://api';
    }

    /**
     * @param  array<int, Address>  $addresses
     */
    private function primaryAddress(array $addresses): ?string
    {
        return $addresses === [] ? null : $addresses[0]->getAddress();
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return list<string>
     */
    private function addressList(array $addresses): array
    {
        return array_values(array_map(static fn (Address $a): string => $a->getAddress(), $addresses));
    }
}
