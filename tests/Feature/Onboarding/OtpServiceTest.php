<?php

declare(strict_types=1);

use App\Enums\VerificationChannel;
use App\Mail\Onboarding\EmailOtpMail;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\VerificationCode;
use App\Services\Otp\OtpCooldownException;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->unverified()->create();
    Mail::fake();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('issues a 6-digit code, queues the email, and stores it hashed', function (): void {
    $otp = app(OtpService::class);

    $record = $otp->issueOrRefresh($this->operator, VerificationChannel::Email);

    expect($record->code_hash)->not->toBeEmpty();
    expect($record->expires_at->isFuture())->toBeTrue();
    expect($record->last_sent_at)->not->toBeNull();

    Mail::assertQueued(EmailOtpMail::class, function (EmailOtpMail $m) {
        return $m->hasTo($this->operator->email)
            && preg_match('/^\d{6}$/', $m->code) === 1;
    });
});

it('refuses to refresh within the 60-second cooldown', function (): void {
    $otp = app(OtpService::class);
    $otp->issueOrRefresh($this->operator, VerificationChannel::Email);

    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    expect(fn () => $otp->issueOrRefresh($this->operator, VerificationChannel::Email))
        ->toThrow(OtpCooldownException::class);
});

it('allows refresh once the cooldown elapses', function (): void {
    $otp = app(OtpService::class);
    $otp->issueOrRefresh($this->operator, VerificationChannel::Email);

    Carbon::setTestNow(Carbon::now()->addSeconds(61));

    $otp->issueOrRefresh($this->operator, VerificationChannel::Email);
    Mail::assertQueued(EmailOtpMail::class, 2);
});

it('verifies the correct code and marks it consumed', function (): void {
    $code = '123456';
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make($code),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
        'last_sent_at' => Carbon::now(),
    ]);

    $result = app(OtpService::class)->verify($this->operator, VerificationChannel::Email, $code);

    expect($result)->toBe(OtpResult::Success);
    $record = VerificationCode::first();
    expect($record->consumed_at)->not->toBeNull();
});

it('returns IncorrectCode for a wrong code and increments attempts', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make('123456'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
    ]);

    $result = app(OtpService::class)->verify($this->operator, VerificationChannel::Email, '999999');

    expect($result)->toBe(OtpResult::IncorrectCode);
    expect(VerificationCode::first()->attempts)->toBe(1);
});

it('returns Expired for a stale code', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make('123456'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->subMinutes(1),
    ]);

    $result = app(OtpService::class)->verify($this->operator, VerificationChannel::Email, '123456');

    expect($result)->toBe(OtpResult::Expired);
});

it('locks the operator for 30 minutes after 5 wrong attempts (PRD §6.1.1)', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make('123456'),
        'attempts' => 4,
        'expires_at' => Carbon::now()->addMinutes(10),
    ]);

    // 5th wrong attempt locks.
    $result = app(OtpService::class)->verify($this->operator, VerificationChannel::Email, '000000');
    expect($result)->toBe(OtpResult::Locked);

    $record = VerificationCode::first();
    expect($record->locked_until)->not->toBeNull();
    expect($record->locked_until->diffInMinutes(now(), absolute: true))->toBeBetween(29.5, 30.5);

    // Even with the right code, locked = locked.
    $result = app(OtpService::class)->verify($this->operator, VerificationChannel::Email, '123456');
    expect($result)->toBe(OtpResult::Locked);
});

it('returns NotFound when no code has been issued', function (): void {
    $result = app(OtpService::class)->verify($this->operator, VerificationChannel::Email, '123456');

    expect($result)->toBe(OtpResult::NotFound);
});

it('returns AlreadyConsumed for an already-verified code', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make('123456'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
        'consumed_at' => Carbon::now(),
    ]);

    $result = app(OtpService::class)->verify($this->operator, VerificationChannel::Email, '123456');

    expect($result)->toBe(OtpResult::AlreadyConsumed);
});

it('isolates email + phone codes — same operator can have one of each', function (): void {
    $otp = app(OtpService::class);
    Http::fake(); // suppress WhatsApp calls

    $email = $otp->issueOrRefresh($this->operator, VerificationChannel::Email);
    $phone = $otp->issueOrRefresh($this->operator, VerificationChannel::Phone);

    expect(VerificationCode::count())->toBe(2);
    expect($email->channel)->toBe(VerificationChannel::Email);
    expect($phone->channel)->toBe(VerificationChannel::Phone);
});

it('sends phone OTPs through Sendpipe as a WhatsApp template', function (): void {
    config()->set('services.sendpipe.api_url', 'https://sendpipe.test/v1');
    config()->set('services.sendpipe.api_key', 'sp_test_dummy');
    config()->set('services.sendpipe.whatsapp_otp_template', 'kev_otp');
    config()->set('services.sendpipe.whatsapp_otp_language', 'en');

    Http::fake([
        'https://sendpipe.test/v1/messages/channel' => Http::response([
            'id' => 'msg_x', 'status' => 'queued', 'channel' => 'whatsapp',
        ]),
    ]);

    app(OtpService::class)->issueOrRefresh($this->operator, VerificationChannel::Phone);

    Http::assertSent(function ($r) {
        return $r->url() === 'https://sendpipe.test/v1/messages/channel'
            && $r['channel'] === 'whatsapp'
            && $r['channel_options']['template_name'] === 'kev_otp'
            && $r['channel_options']['template_language'] === 'en'
            && is_array($r['channel_options']['template_params'])
            && preg_match('/^\d{6}$/', $r['channel_options']['template_params'][0]) === 1
            && $r->header('X-SendPipe-Key')[0] === 'sp_test_dummy';
    });
});

it('reports correct resend cooldown', function (): void {
    $otp = app(OtpService::class);
    $otp->issueOrRefresh($this->operator, VerificationChannel::Email);

    expect($otp->resendCooldownSeconds($this->operator, VerificationChannel::Email))
        ->toBeBetween(58, 60);

    Carbon::setTestNow(Carbon::now()->addSeconds(35));
    expect($otp->resendCooldownSeconds($this->operator, VerificationChannel::Email))
        ->toBeBetween(23, 25);

    Carbon::setTestNow(Carbon::now()->addSeconds(60));
    expect($otp->resendCooldownSeconds($this->operator, VerificationChannel::Email))->toBe(0);
});
