<?php

declare(strict_types=1);

use App\Enums\VerificationChannel;
use App\Events\TenantOnboarded;
use App\Livewire\Onboarding\EmailVerification;
use App\Mail\Onboarding\EmailOtpMail;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\VerificationCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'email-otp-test']);
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->unverified()->create();
    Mail::fake();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('queues an OTP mail on mount', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(EmailVerification::class);

    Mail::assertQueued(EmailOtpMail::class);
});

it('marks the email verified on correct code, fires TenantOnboarded and opens the dashboard', function (): void {
    Event::fake([TenantOnboarded::class]);

    // Bootstrap a known code so we can submit a deterministic value.
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make('424242'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
        'last_sent_at' => Carbon::now(),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(EmailVerification::class)
        ->set('code', '424242')
        ->call('submit')
        ->assertRedirect(route('tenant.dashboard'))
        ->assertHasNoErrors();

    expect($this->operator->fresh()->email_verified_at)->not->toBeNull();
    Event::assertDispatched(TenantOnboarded::class);
});

it('shows an error for incorrect codes', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make('424242'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(EmailVerification::class)
        ->set('code', '999999')
        ->call('submit')
        ->assertHasErrors(['code']);

    expect($this->operator->fresh()->email_verified_at)->toBeNull();
});

it('rejects codes that are not 6 digits', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(EmailVerification::class)
        ->set('code', '12345')
        ->call('submit')
        ->assertHasErrors(['code']);
});

it('shows a "code expired" error after 10 minutes', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make('424242'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(EmailVerification::class)
        ->set('code', '424242')
        ->call('submit')
        ->assertHasErrors(['code']);
});

it('refuses to resend within the 60-second cooldown', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(EmailVerification::class)
        ->call('resend')
        ->assertHasErrors(['code']);
});

it('resends successfully after the cooldown elapses', function (): void {
    $component = Livewire::actingAs($this->operator, 'web')->test(EmailVerification::class);

    Carbon::setTestNow(Carbon::now()->addSeconds(61));
    $component->call('resend')->assertHasNoErrors();

    Mail::assertQueued(EmailOtpMail::class, 2);
});

it('locks the operator after 5 wrong submissions (PRD §6.1.1)', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Email->value,
        'code_hash' => Hash::make('424242'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
    ]);

    $component = Livewire::actingAs($this->operator, 'web')->test(EmailVerification::class);

    for ($i = 0; $i < 4; $i++) {
        $component->set('code', '000000')->call('submit')->assertHasErrors(['code']);
    }

    // The 5th wrong attempt locks; even the correct code is now rejected.
    $component->set('code', '000000')->call('submit')->assertHasErrors(['code']);
    $component->set('code', '424242')->call('submit')->assertHasErrors(['code']);

    expect($this->operator->fresh()->email_verified_at)->toBeNull();
});
