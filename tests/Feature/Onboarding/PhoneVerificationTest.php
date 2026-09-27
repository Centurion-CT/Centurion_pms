<?php

declare(strict_types=1);

use App\Enums\VerificationChannel;
use App\Events\TenantOnboarded;
use App\Livewire\Onboarding\PhoneVerification;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\VerificationCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'phone-otp-test']);
    $this->tenant->makeCurrent();
    // Property + email verified — only the phone step remains.
    $this->operator = Operator::factory()
        ->for($this->tenant)
        ->unverified()
        ->create(['email_verified_at' => now()]);
    Property::factory()->for($this->tenant)->create();
    // No Http::fake() here. SENDPIPE_API_KEY is intentionally unset in
    // phpunit.xml so WhatsAppOtpService no-ops the outbound HTTP call.
    // Tests that DO want to observe the Sendpipe traffic set the api_key +
    // their own Http::fake explicitly.
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('issues a WhatsApp OTP through Sendpipe on mount', function (): void {
    config()->set('services.sendpipe.api_url', 'https://sendpipe.test/v1');
    config()->set('services.sendpipe.api_key', 'sp_test_dummy');
    Http::fake([
        'https://sendpipe.test/v1/messages/channel' => Http::response([
            'id' => 'msg_x', 'status' => 'queued', 'channel' => 'whatsapp',
        ]),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class);

    Http::assertSent(fn ($r) => $r['channel'] === 'whatsapp'
        && $r['channel_options']['template_name'] === 'kev_otp');
});

it('marks the phone verified on correct code + fires TenantOnboarded + redirects to dashboard', function (): void {
    Event::fake();
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Phone->value,
        'code_hash' => Hash::make('606060'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
        'last_sent_at' => Carbon::now(),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->set('code', '606060')
        ->call('submit')
        ->assertRedirect(route('tenant.dashboard'))
        ->assertHasNoErrors();

    expect($this->operator->fresh()->phone_verified_at)->not->toBeNull();

    Event::assertDispatched(TenantOnboarded::class, function (TenantOnboarded $e) {
        return $e->tenant->id === $this->tenant->id
            && $e->operator->id === $this->operator->id;
    });
});

it('does NOT fire TenantOnboarded on a failed verification', function (): void {
    Event::fake();
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Phone->value,
        'code_hash' => Hash::make('606060'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->set('code', '999999')
        ->call('submit');

    Event::assertNotDispatched(TenantOnboarded::class);
});

it('shows a "code expired" error after 10 minutes', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Phone->value,
        'code_hash' => Hash::make('606060'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->set('code', '606060')
        ->call('submit')
        ->assertHasErrors(['code']);
});

it('locks after 5 wrong attempts', function (): void {
    VerificationCode::query()->create([
        'operator_id' => $this->operator->id,
        'channel' => VerificationChannel::Phone->value,
        'code_hash' => Hash::make('606060'),
        'attempts' => 0,
        'expires_at' => Carbon::now()->addMinutes(10),
    ]);

    $component = Livewire::actingAs($this->operator, 'web')->test(PhoneVerification::class);
    for ($i = 0; $i < 5; $i++) {
        $component->set('code', '000000')->call('submit')->assertHasErrors(['code']);
    }
    // Even the correct code fails when locked.
    $component->set('code', '606060')->call('submit')->assertHasErrors(['code']);

    expect($this->operator->fresh()->phone_verified_at)->toBeNull();
});

/* ---------- failure-mode coverage (was causing 500s in prod) ---------- */

it('renders the phone-entry form instead of 500ing when the operator has no phone', function (): void {
    // Phone was optional at registration — plenty of live operators
    // hit /onboarding/phone with a NULL phone column. mount() used to
    // call WhatsAppOtpService::sendOtp which throws RuntimeException,
    // 500ing the request.
    $this->operator->forceFill(['phone' => null])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->assertSuccessful()
        ->assertSeeHtml('data-testid="phone-entry-form"')
        ->assertDontSeeHtml('data-testid="phone-otp-form"');
});

it('saves an operator-supplied phone and dispatches an OTP to it', function (): void {
    config()->set('services.sendpipe.api_url', 'https://sendpipe.test/v1');
    config()->set('services.sendpipe.api_key', 'sp_test_dummy');
    Http::fake(['sendpipe.test/*' => Http::response(['id' => 'msg_1', 'status' => 'queued'], 200)]);

    $this->operator->forceFill(['phone' => null])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->set('phone', '+2348012345678')
        ->call('savePhone')
        ->assertHasNoErrors();

    expect($this->operator->fresh()->phone)->toBe('+2348012345678');
    Http::assertSentCount(1);
});

it('rejects an obviously bad phone number', function (): void {
    $this->operator->forceFill(['phone' => null])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->set('phone', 'not a number')
        ->call('savePhone')
        ->assertHasErrors(['phone']);
});

it('lets the operator skip the phone step, fires TenantOnboarded, and unblocks the dashboard', function (): void {
    // Sendpipe/WhatsApp can be down or the number unreachable — the
    // escape hatch keeps them out of a dead-end without pretending the
    // number is verified.
    Event::fake();

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->call('skip')
        ->assertRedirect(route('tenant.dashboard'))
        ->assertHasNoErrors();

    $fresh = $this->operator->fresh();
    expect($fresh->phone_verified_at)->toBeNull();
    expect($fresh->phone_verification_skipped_at)->not->toBeNull();
    expect($fresh->hasResolvedPhoneStep())->toBeTrue();

    Event::assertDispatched(TenantOnboarded::class, function (TenantOnboarded $e) {
        return $e->tenant->id === $this->tenant->id
            && $e->operator->id === $this->operator->id;
    });
});

it('does not re-fire TenantOnboarded when skip is called on an already-resolved operator', function (): void {
    Event::fake();
    $this->operator->markPhoneVerificationSkipped();

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->call('skip')
        ->assertRedirect(route('tenant.dashboard'));

    Event::assertNotDispatched(TenantOnboarded::class);
});

it('shows a retryable banner instead of 500ing when Sendpipe is unreachable', function (): void {
    // Real prod failure mode — key is set, network is broken. Was
    // bubbling ConnectionException all the way to the browser.
    config()->set('services.sendpipe.api_url', 'https://sendpipe.test/v1');
    config()->set('services.sendpipe.api_key', 'sp_test_dummy');
    Http::fake(['sendpipe.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Failed to connect')]);

    Livewire::actingAs($this->operator, 'web')
        ->test(PhoneVerification::class)
        ->assertSuccessful()
        ->assertSet('sendError', fn ($v) => is_string($v) && str_contains($v, 'couldn\'t send'));
});
