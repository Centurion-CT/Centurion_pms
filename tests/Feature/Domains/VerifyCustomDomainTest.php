<?php

declare(strict_types=1);

use App\Actions\Domains\AttachCustomDomain;
use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Models\Tenant;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\FakeDnsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config()->set('keverna.custom_domain_cname_target', 'cname.keverna.test');

    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);
});

it('marks the tenant Active when CNAME + TXT both match', function (): void {
    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'reservations.grandpalace.com');
    $tenant->refresh();

    $this->dns
        ->withCname('reservations.grandpalace.com', 'cname.keverna.test')
        ->withTxt('reservations.grandpalace.com', [$tenant->custom_domain_verification_token]);

    (new VerifyCustomDomain($tenant->id))->handle($this->dns);

    $tenant->refresh();
    expect($tenant->custom_domain_status)->toBe(CustomDomainStatus::Active);
    expect($tenant->domain_verified_at)->not->toBeNull();
});

it('stays Pending and re-queues itself when records are missing', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'pending.example.com');

    (new VerifyCustomDomain($tenant->id))->handle($this->dns);

    $tenant->refresh();
    expect($tenant->custom_domain_status)->toBe(CustomDomainStatus::Pending);
    expect($tenant->domain_verified_at)->toBeNull();

    Queue::assertPushed(VerifyCustomDomain::class);
});

it('rejects mismatched CNAME target (operator typed the wrong target)', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'wrong-target.example.com');
    $tenant->refresh();

    $this->dns
        ->withCname('wrong-target.example.com', 'cdn.cloudflare.com') // not Keverna
        ->withTxt('wrong-target.example.com', [$tenant->custom_domain_verification_token]);

    (new VerifyCustomDomain($tenant->id))->handle($this->dns);

    expect($tenant->fresh()->custom_domain_status)->toBe(CustomDomainStatus::Pending);
});

it('rejects when TXT record is missing or wrong (CNAME set but no proof of ownership)', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'no-txt.example.com');

    $this->dns
        ->withCname('no-txt.example.com', 'cname.keverna.test')
        ->withTxt('no-txt.example.com', ['some-other-tenant-token']);

    (new VerifyCustomDomain($tenant->id))->handle($this->dns);

    expect($tenant->fresh()->custom_domain_status)->toBe(CustomDomainStatus::Pending);
});

it('flips to Error after 48 hours of unsuccessful polling (PRD §6.6.2)', function (): void {
    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'gives-up.example.com');

    // 49 hours of no DNS — exceeds the PRD 48h deadline.
    Carbon::setTestNow(Carbon::now()->addHours(49));

    (new VerifyCustomDomain($tenant->id))->handle($this->dns);

    expect($tenant->fresh()->custom_domain_status)->toBe(CustomDomainStatus::Error);
});

it('skips silently if the tenant has no custom_domain attached', function (): void {
    $tenant = Tenant::factory()->create(['custom_domain' => null]);

    (new VerifyCustomDomain($tenant->id))->handle($this->dns);

    expect($tenant->fresh()->custom_domain_status)->toBeNull();
});

it('is a no-op once the domain is already Active', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()
        ->withCustomDomain('already-active.com')
        ->create();
    $tenant->forceFill([
        'custom_domain_status' => CustomDomainStatus::Active,
        'custom_domain_verification_token' => 'keverna-verify=stable',
    ])->save();

    (new VerifyCustomDomain($tenant->id))->handle($this->dns);

    Queue::assertNotPushed(VerifyCustomDomain::class);
    expect($tenant->fresh()->custom_domain_status)->toBe(CustomDomainStatus::Active);
});

it('AttachCustomDomain rejects domains already owned by another tenant', function (): void {
    Tenant::factory()->withCustomDomain('taken.com')->create();

    $other = Tenant::factory()->create();

    expect(fn () => app(AttachCustomDomain::class)->handle($other, 'taken.com'))
        ->toThrow(ValidationException::class);
});

it('AttachCustomDomain dispatches the first verification attempt', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'fresh.example.com');

    Queue::assertPushed(VerifyCustomDomain::class,
        fn ($job) => $job->tenantId === $tenant->id);
});

it('AttachCustomDomain rotates the verification token on re-attach', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'rotate.example.com');
    $firstToken = $tenant->fresh()->custom_domain_verification_token;

    app(AttachCustomDomain::class)->handle($tenant, 'rotate.example.com');
    $secondToken = $tenant->fresh()->custom_domain_verification_token;

    expect($secondToken)->not->toBe($firstToken);
});
