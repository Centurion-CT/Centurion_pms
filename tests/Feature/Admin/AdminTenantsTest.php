<?php

declare(strict_types=1);

use App\Enums\CustomDomainStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\VerificationChannel;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Livewire\Admin\Tenants\Index as AdminTenantsIndex;
use App\Livewire\Admin\Tenants\Show as AdminTenantsShow;
use App\Mail\Onboarding\EmailOtpMail;
use App\Models\Activity;
use App\Models\Admin;
use App\Models\Operator;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\VerificationCode;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = Admin::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/* ---------- Index ---------- */

it('lists every tenant in the platform regardless of scope', function (): void {
    Tenant::factory()->create(['slug' => 'alpha']);
    Tenant::factory()->create(['slug' => 'beta']);
    Tenant::factory()->create(['slug' => 'gamma']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsIndex::class)
        ->assertSee('alpha')
        ->assertSee('beta')
        ->assertSee('gamma');
});

it('filters by status', function (): void {
    Tenant::factory()->create(['slug' => 'active-one', 'status' => TenantStatus::Active]);
    Tenant::factory()->create(['slug' => 'locked-one', 'status' => TenantStatus::Locked]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsIndex::class)
        ->set('statusFilter', TenantStatus::Locked->value)
        ->assertSee('locked-one')
        ->assertDontSee('active-one');
});

it('filters by slug/name search', function (): void {
    Tenant::factory()->create(['slug' => 'maitama-suites', 'name' => 'Maitama Suites']);
    Tenant::factory()->create(['slug' => 'asokoro-villas', 'name' => 'Asokoro Villas']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsIndex::class)
        ->set('search', 'asokoro')
        ->assertSee('Asokoro Villas')
        ->assertDontSee('Maitama Suites');
});

/* ---------- Show + actions ---------- */

it('renders the tenant detail page with summary + actions', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'detail-test']);

    $response = $this->actingAs($this->admin, 'admin')
        ->get('http://admin.keverna.test/admin/tenants/'.$tenant->slug);

    $response->assertOk();
    $response->assertSeeLivewire(AdminTenantsShow::class);
    $response->assertSeeHtml('data-testid="extend-subscription"');
});

it('extends a subscription and writes an audit row', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
    Subscription::factory()->for($tenant)->create([
        'expires_at' => '2030-09-01 00:00:00',
        'status' => SubscriptionStatus::Active,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $tenant])
        ->set('extendDays', 60)
        ->call('extend')
        ->assertHasNoErrors();

    $sub = Subscription::query()->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->id)->first();
    expect($sub->expires_at->toDateString())->toBe('2030-10-31');

    $audit = Activity::query()->withoutGlobalScopes()->where('event', 'subscription_extended')->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->causer_id)->toBe($this->admin->id);
    expect($audit->properties->get('days'))->toBe(60);
});

it('anchors extend to today when the current expiry is past', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Locked]);
    Subscription::factory()->for($tenant)->create([
        'expires_at' => '2030-06-01 00:00:00',  // 2 months past
        'status' => SubscriptionStatus::Expired,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $tenant])
        ->set('extendDays', 30)
        ->call('extend');

    $sub = Subscription::query()->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->id)->first();
    // Anchored to today (2030-08-01) + 30 = 2030-08-31, NOT 2030-06-01+30=07-01
    expect($sub->expires_at->toDateString())->toBe('2030-08-31');
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('re-verifies a custom domain by enqueuing VerifyCustomDomain', function (): void {
    Bus::fake();
    $tenant = Tenant::factory()->create([
        'custom_domain' => 'stay.example.com',
        'custom_domain_status' => CustomDomainStatus::Error,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $tenant])
        ->call('reverifyDomain')
        ->assertHasNoErrors();

    Bus::assertDispatched(VerifyCustomDomain::class, fn ($job) => $job->tenantId === $tenant->id);

    $audit = Activity::query()->withoutGlobalScopes()->where('event', 'domain_reverify_queued')->first();
    expect($audit)->not->toBeNull();
});

it('refuses to re-verify a tenant with no custom domain attached', function (): void {
    Bus::fake();
    $tenant = Tenant::factory()->create(['custom_domain' => null]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $tenant])
        ->call('reverifyDomain');

    expect($component->get('errorMessage'))->toContain('no custom domain');
    Bus::assertNotDispatched(VerifyCustomDomain::class);
});

/* ---------- Onboarding OTP resend ---------- */

it('resends the email verification code for a founder stuck in onboarding', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create(['slug' => 'stuck-founder']);
    $tenant->execute(fn () => Operator::factory()->for($tenant)->create([
        'email' => 'founder@stuck.test',
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]));

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $tenant])
        ->call('resendEmailVerification')
        ->assertHasNoErrors()
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok'
            && ($p['message'] ?? null) === 'Resent email verification code to the founder operator.');

    Mail::assertQueued(EmailOtpMail::class);

    $tenant->execute(function () use ($tenant): void {
        $code = VerificationCode::query()
            ->where('channel', VerificationChannel::Email->value)
            ->first();
        expect($code)->not->toBeNull();
        expect($code->consumed_at)->toBeNull();
    });

    $audit = Activity::query()->withoutGlobalScopes()
        ->where('event', 'onboarding_otp_resent')->first();
    expect($audit)->not->toBeNull();
    expect($audit->properties->get('channel'))->toBe('email');
});

it('refuses to resend when the founder has already verified that channel', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $tenant->execute(fn () => Operator::factory()->for($tenant)->create([
        'email_verified_at' => Carbon::now(),
    ]));

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $tenant])
        ->call('resendEmailVerification');

    expect($component->get('errorMessage'))->toContain('already verified');
    Mail::assertNothingQueued();
});

it('bypasses the 60-second cooldown for admin resends (issueOrRefresh allowImmediate)', function (): void {
    // The wizard blocks another send inside 60s of last_sent_at. The admin
    // path is explicitly allowed to punch through — the whole point is
    // support unsticking a founder whose earlier code got lost.
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $tenant->execute(function () use ($tenant): void {
        $op = Operator::factory()->for($tenant)->create(['email_verified_at' => null]);
        VerificationCode::query()->create([
            'operator_id' => $op->id,
            'channel' => VerificationChannel::Email->value,
            'code_hash' => bcrypt('123456'),
            'attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes(10),
            'last_sent_at' => Carbon::now(),  // just sent, cooldown hot
        ]);
    });

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $tenant])
        ->call('resendEmailVerification')
        ->assertHasNoErrors();

    Mail::assertQueued(EmailOtpMail::class);
});

it('hides the resend UI once every channel is verified', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->execute(fn () => Operator::factory()->for($tenant)->create([
        'email_verified_at' => Carbon::now(),
        'phone_verified_at' => Carbon::now(),
    ]));

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $tenant])
        ->assertDontSeeHtml('data-testid="resend-verification"');
});
