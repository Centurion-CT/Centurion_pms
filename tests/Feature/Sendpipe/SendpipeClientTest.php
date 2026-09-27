<?php

declare(strict_types=1);

use App\Services\Sendpipe\SendpipeClient;
use App\Services\Sendpipe\SendpipeException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.sendpipe.api_url', 'https://sendpipe.test/v1');
    config()->set('services.sendpipe.api_key', 'sp_test_dummy');
    config()->set('services.sendpipe.default_from', 'noreply@keverna.test');
});

function sendpipeOk(): mixed
{
    return Http::response([
        'id' => 'msg_abc123',
        'project_id' => 'proj_xyz',
        'status' => 'queued',
        'channel' => 'email',
        'created_at' => '2026-05-23T10:00:00Z',
    ]);
}

it('posts email payloads to /messages/channel with X-SendPipe-Key', function (): void {
    Http::fake(['https://sendpipe.test/v1/messages/channel' => sendpipeOk()]);

    app(SendpipeClient::class)->sendEmail([
        'to' => ['op@example.test'],
        'subject' => 'Welcome',
        'html' => '<h1>Hi</h1>',
    ]);

    Http::assertSent(function ($r) {
        return $r->url() === 'https://sendpipe.test/v1/messages/channel'
            && $r['channel'] === 'email'
            && $r['from'] === 'noreply@keverna.test'
            && $r['to'] === ['op@example.test']
            && $r['subject'] === 'Welcome'
            && $r['html'] === '<h1>Hi</h1>'
            && $r->header('X-SendPipe-Key')[0] === 'sp_test_dummy';
    });
});

it('forwards an explicit from address when provided', function (): void {
    Http::fake(['https://sendpipe.test/v1/messages/channel' => sendpipeOk()]);

    app(SendpipeClient::class)->sendEmail([
        'from' => 'override@x.test',
        'to' => ['op@x.test'],
        'subject' => 'Test',
        'text' => 'hi',
    ]);

    Http::assertSent(fn ($r) => $r['from'] === 'override@x.test');
});

it('attaches an auto-generated Idempotency-Key when caller does not supply one', function (): void {
    Http::fake(['https://sendpipe.test/v1/messages/channel' => sendpipeOk()]);

    app(SendpipeClient::class)->sendEmail([
        'to' => ['op@x.test'],
        'subject' => 'Test',
        'html' => '<p>x</p>',
    ]);

    Http::assertSent(function ($r) {
        $key = $r->header('Idempotency-Key')[0] ?? null;

        return is_string($key) && str_starts_with($key, 'kev-') && strlen($key) > 8;
    });
});

it('honors a caller-supplied idempotency_key', function (): void {
    Http::fake(['https://sendpipe.test/v1/messages/channel' => sendpipeOk()]);

    app(SendpipeClient::class)->sendEmail([
        'to' => ['op@x.test'],
        'subject' => 'Test',
        'html' => '<p>x</p>',
        'idempotency_key' => 'custom-key-123',
    ]);

    Http::assertSent(fn ($r) => $r->header('Idempotency-Key')[0] === 'custom-key-123');
});

it('sends WhatsApp template messages with the correct channel_options shape', function (): void {
    Http::fake(['https://sendpipe.test/v1/messages/channel' => sendpipeOk()]);

    app(SendpipeClient::class)->sendWhatsAppTemplate(
        to: ['+2348012345678'],
        templateName: 'kev_otp',
        templateLanguage: 'en',
        templateParams: ['424242'],
    );

    Http::assertSent(function ($r) {
        return $r['channel'] === 'whatsapp'
            && $r['to'] === ['2348012345678']   // leading + stripped
            && $r['channel_options']['template_name'] === 'kev_otp'
            && $r['channel_options']['template_language'] === 'en'
            && $r['channel_options']['template_params'] === ['424242'];
    });
});

it('omits template_params when none are passed', function (): void {
    Http::fake(['https://sendpipe.test/v1/messages/channel' => sendpipeOk()]);

    app(SendpipeClient::class)->sendWhatsAppTemplate(
        to: ['+2348000000001'],
        templateName: 'kev_welcome',
        templateLanguage: 'en',
    );

    Http::assertSent(fn ($r) => ! isset($r['channel_options']['template_params']));
});

it('throws SendpipeException with structured error envelope on 4xx', function (): void {
    Http::fake([
        'https://sendpipe.test/v1/messages/channel' => Http::response([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Recipient invalid',
                'details' => [
                    ['field' => 'to', 'message' => 'Must be a valid email address'],
                ],
            ],
        ], 400),
    ]);

    try {
        app(SendpipeClient::class)->sendEmail([
            'to' => ['not-an-email'],
            'subject' => 'x',
            'html' => '<p>x</p>',
        ]);
        $this->fail('Expected SendpipeException');
    } catch (SendpipeException $e) {
        expect($e->statusCode)->toBe(400);
        expect($e->errorCode)->toBe('VALIDATION_ERROR');
        expect($e->getMessage())->toBe('Recipient invalid');
        expect($e->details)->toBe([
            ['field' => 'to', 'message' => 'Must be a valid email address'],
        ]);
    }
});

it('surfaces 429 RATE_LIMITED responses', function (): void {
    Http::fake([
        'https://sendpipe.test/v1/messages/channel' => Http::response([
            'error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests'],
        ], 429),
    ]);

    try {
        app(SendpipeClient::class)->sendEmail([
            'to' => ['op@x.test'],
            'subject' => 'x',
            'html' => '<p>x</p>',
        ]);
        $this->fail('Expected SendpipeException');
    } catch (SendpipeException $e) {
        expect($e->statusCode)->toBe(429);
        expect($e->errorCode)->toBe('RATE_LIMITED');
    }
});

it('returns the parsed response body on success (id + status)', function (): void {
    Http::fake([
        'https://sendpipe.test/v1/messages/channel' => Http::response([
            'id' => 'msg_xyz',
            'status' => 'queued',
            'channel' => 'whatsapp',
            'created_at' => '2026-05-23T10:00:00Z',
        ]),
    ]);

    $response = app(SendpipeClient::class)->sendWhatsAppTemplate(
        to: ['+2348012345678'],
        templateName: 'kev_otp',
        templateLanguage: 'en',
        templateParams: ['111111'],
    );

    expect($response['id'])->toBe('msg_xyz');
    expect($response['status'])->toBe('queued');
});
