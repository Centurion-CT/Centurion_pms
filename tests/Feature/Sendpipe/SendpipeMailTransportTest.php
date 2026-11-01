<?php

declare(strict_types=1);

use App\Mail\Onboarding\EmailOtpMail;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\Sendpipe\SendpipeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config()->set('mail.default', 'sendpipe');
    config()->set('services.sendpipe.api_url', 'https://sendpipe.test/v1');
    config()->set('services.sendpipe.api_key', 'sp_test_dummy');
    config()->set('services.sendpipe.default_from', 'noreply@keverna.test');
    config()->set('mail.from.address', 'noreply@keverna.test');
    config()->set('mail.from.name', 'Keverna');
    config()->set('queue.default', 'sync');

    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'name' => 'Amara',
        'email' => 'amara@x.test',
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('routes a Mailable through Sendpipe when MAIL_MAILER=sendpipe', function (): void {
    Http::fake([
        'https://sendpipe.test/v1/messages/channel' => Http::response([
            'id' => 'msg_x', 'status' => 'queued', 'channel' => 'email',
        ]),
    ]);

    Mail::to($this->operator->email)->send(new EmailOtpMail($this->operator, '424242'));

    Http::assertSent(function ($r) {
        return $r->url() === 'https://sendpipe.test/v1/messages/channel'
            && $r['channel'] === 'email'
            && $r['from'] === 'noreply@keverna.test'
            && $r['to'] === ['amara@x.test']
            && str_contains((string) $r['subject'], config('brand.name'))
            && str_contains((string) $r['html'], '424242')
            && $r->header('X-SendPipe-Key')[0] === 'sp_test_dummy';
    });
});

it('propagates Sendpipe errors instead of silently swallowing them', function (): void {
    Http::fake([
        'https://sendpipe.test/v1/messages/channel' => Http::response([
            'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Bad key'],
        ], 401),
    ]);

    expect(fn () => Mail::to($this->operator->email)
        ->send(new EmailOtpMail($this->operator, '424242')))
        ->toThrow(SendpipeException::class);
});
