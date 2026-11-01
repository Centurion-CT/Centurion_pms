<?php

declare(strict_types=1);

use App\Actions\Domains\AttachCustomDomain;
use App\Actions\Domains\CheckOwnDomain;
use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Models\Tenant;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\FakeDnsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);
    Http::fake(); // the first live check after verifying gets an empty 200
});

function verifyDomain(Tenant $tenant, FakeDnsResolver $dns): void
{
    (new VerifyCustomDomain($tenant->id))->handle($dns, app(CheckOwnDomain::class));
}

it('marks the domain verified once the TXT record carries the token', function (): void {
    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'book.grandpalace.com');
    $tenant->refresh();

    $this->dns->withTxt('_centurion-verify.book.grandpalace.com', [$tenant->custom_domain_verification_token]);
    verifyDomain($tenant, $this->dns);

    $tenant->refresh();
    expect($tenant->custom_domain_status)->toBe(CustomDomainStatus::Active)
        ->and($tenant->domain_verified_at)->not->toBeNull()
        ->and($tenant->ownDomainIsLive())->toBeFalse(); // not answering for it yet
});

it('reads the TXT record from its own name, not the domain itself', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'grandpalace.ng');
    $tenant->refresh();

    $this->dns->withTxt('grandpalace.ng', [$tenant->custom_domain_verification_token]);
    verifyDomain($tenant, $this->dns);

    expect($tenant->fresh()->domain_verified_at)->toBeNull();
});

it('stays Pending and re-queues itself when the record is missing', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'pending.example.com');

    verifyDomain($tenant, $this->dns);

    $tenant->refresh();
    expect($tenant->custom_domain_status)->toBe(CustomDomainStatus::Pending);
    expect($tenant->domain_verified_at)->toBeNull();

    Queue::assertPushed(VerifyCustomDomain::class);
});

it('rejects a TXT record carrying another token', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'no-txt.example.com');

    $this->dns->withTxt('_centurion-verify.no-txt.example.com', ['some-other-tenant-token']);
    verifyDomain($tenant, $this->dns);

    expect($tenant->fresh()->custom_domain_status)->toBe(CustomDomainStatus::Pending);
});

it('flips to Error after 48 hours of unsuccessful polling (PRD §6.6.2)', function (): void {
    $tenant = Tenant::factory()->create();
    app(AttachCustomDomain::class)->handle($tenant, 'gives-up.example.com');

    // 49 hours of no DNS — exceeds the PRD 48h deadline.
    Carbon::setTestNow(Carbon::now()->addHours(49));

    verifyDomain($tenant, $this->dns);

    expect($tenant->fresh()->custom_domain_status)->toBe(CustomDomainStatus::Error);
});

it('skips silently if the tenant has no custom_domain attached', function (): void {
    $tenant = Tenant::factory()->create(['custom_domain' => null]);

    verifyDomain($tenant, $this->dns);

    expect($tenant->fresh()->custom_domain_status)->toBeNull();
});

it('is a no-op once the domain is already Active', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()
        ->withCustomDomain('already-active.com')
        ->create();
    $tenant->forceFill(['custom_domain_verification_token' => 'centurion-verify=stable'])->save();

    verifyDomain($tenant, $this->dns);

    Queue::assertNotPushed(VerifyCustomDomain::class);
    expect($tenant->fresh()->custom_domain_status)->toBe(CustomDomainStatus::Active);
});

it('AttachCustomDomain rejects domains already owned by another tenant', function (): void {
    Tenant::factory()->withCustomDomain('taken.com')->create();

    $other = Tenant::factory()->create();

    expect(fn () => app(AttachCustomDomain::class)->handle($other, 'taken.com'))
        ->toThrow(ValidationException::class);
});

it('AttachCustomDomain refuses platform addresses', function (string $domain): void {
    $tenant = Tenant::factory()->create(['slug' => 'mine']);

    expect(fn () => app(AttachCustomDomain::class)->handle($tenant, $domain))
        ->toThrow(ValidationException::class);
})->with(['keverna.test', 'www.keverna.test', 'someone-else.keverna.test', 'mine.keverna.test']);

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

    expect($secondToken)->toStartWith('centurion-verify=')->not->toBe($firstToken);
});
