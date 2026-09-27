<?php

declare(strict_types=1);

namespace App\Services\Sendpipe;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin client around the Sendpipe v1 multi-channel dispatch API
 * (`POST /v1/messages/channel`). Sendpipe is FregateLab's communications
 * control plane — Keverna sends ALL email + WhatsApp through it instead of
 * talking to individual providers (SES, Resend, Meta Cloud) directly.
 *
 * Auth: `X-SendPipe-Key: sp_live_...` (project-scoped).
 * Idempotency: optional `Idempotency-Key` header; duplicate requests within
 *   24h return the original response. We auto-generate one per call if the
 *   caller does not supply one, so transient retries (HTTP timeout +
 *   queue retry) cannot double-send.
 *
 * Spec source: docs/sendpipe-API_REFERENCE.md (local) + sendpipe.org/docs.
 */
class SendpipeClient
{
    /**
     * Email send.
     *
     * @param  array{from?: ?string, to: list<string>, subject: string, html?: ?string, text?: ?string, cc?: list<string>, bcc?: list<string>, reply_to?: ?string, tags?: list<string>, idempotency_key?: ?string}  $payload
     * @return array<string, mixed> raw Sendpipe response (id, status, channel, created_at)
     */
    public function sendEmail(array $payload): array
    {
        $body = array_filter([
            'channel' => 'email',
            'from' => $payload['from'] ?? (string) config('services.sendpipe.default_from'),
            'to' => $payload['to'],
            'subject' => $payload['subject'],
            'html' => $payload['html'] ?? null,
            'text' => $payload['text'] ?? null,
            'cc' => $payload['cc'] ?? null,
            'bcc' => $payload['bcc'] ?? null,
            'reply_to' => $payload['reply_to'] ?? null,
            'tags' => $payload['tags'] ?? null,
        ], static fn ($v) => $v !== null && $v !== []);

        return $this->dispatch($body, $payload['idempotency_key'] ?? null);
    }

    /**
     * WhatsApp template send. Meta requires a pre-approved template for any
     * message sent outside an active 24h session window — OTPs always fall
     * into this category, so we use templates by default.
     *
     * @param  list<string>  $to  E.164 phone numbers (with or without leading +)
     * @param  list<string>  $templateParams  positional placeholders for the template body
     * @return array<string, mixed>
     */
    public function sendWhatsAppTemplate(
        array $to,
        string $templateName,
        string $templateLanguage,
        array $templateParams = [],
        ?string $idempotencyKey = null,
    ): array {
        return $this->dispatch([
            'channel' => 'whatsapp',
            'to' => array_map(static fn (string $n): string => ltrim($n, '+'), $to),
            'channel_options' => array_filter([
                'template_name' => $templateName,
                'template_language' => $templateLanguage,
                'template_params' => $templateParams !== [] ? $templateParams : null,
            ], static fn ($v) => $v !== null),
        ], $idempotencyKey);
    }

    /**
     * Free-form WhatsApp session message — only deliverable when the
     * recipient has messaged the property WhatsApp number in the past
     * 24 hours (Meta's session window). Outside that window Sendpipe
     * returns an error; callers should treat delivery as best-effort
     * and catch to avoid blocking the operator flow.
     *
     * @param  list<string>  $to  E.164 phone numbers (with or without leading +)
     * @return array<string, mixed>
     */
    public function sendWhatsAppText(
        array $to,
        string $body,
        ?string $idempotencyKey = null,
    ): array {
        return $this->dispatch([
            'channel' => 'whatsapp',
            'to' => array_map(static fn (string $n): string => ltrim($n, '+'), $to),
            'body' => $body,
        ], $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function dispatch(array $body, ?string $idempotencyKey): array
    {
        $idempotencyKey ??= 'kev-'.(string) Str::uuid();

        try {
            $response = $this->http()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/messages/channel', $body)
                ->throw();
        } catch (RequestException $e) {
            throw $this->translateError($e->response);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json();

        return $decoded;
    }

    private function translateError(Response $response): SendpipeException
    {
        /** @var array<string, mixed>|null $body */
        $body = $response->json();
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        return new SendpipeException(
            statusCode: $response->status(),
            errorCode: (string) ($error['code'] ?? 'UNKNOWN'),
            message: (string) ($error['message'] ?? $response->reason()),
            details: array_values(array_filter(
                (array) ($error['details'] ?? []),
                static fn ($d) => is_array($d) && isset($d['field'], $d['message']),
            )),
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.sendpipe.api_url'))
            ->withHeaders([
                'X-SendPipe-Key' => (string) config('services.sendpipe.api_key'),
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }
}
