<?php

declare(strict_types=1);

use App\Actions\Domains\CheckOwnDomain;
use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\AttachCustomDomainToDokwe;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Models\Tenant;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\FakeDnsResolver;
use App\Services\Dokwe\DokweClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('dokwe.base_url', 'https://dokwe.test/api');
    config()->set('dokwe.api_key', 'dk_pak_test');
    config()->set('dokwe.project_id', '9b22e799-a6eb-43db-841e-8715451d5a91');
    config()->set('keverna.custom_domain_cname_target', 'cname.keverna.test');
});

function dokweUrl2(string $path = ''): string
{
    return 'https://dokwe.test/api/domains/9b22e799-a6eb-43db-841e-8715451d5a91'.$path;
}

it('attaches the operator custom_domain and stamps custom_domain_dokwe_id', function (): void {
    Http::fake([
        dokweUrl2() => Http::response([
            'id' => 'dokwe-cd-1', 'projectId' => '9b22e799-a6eb-43db-841e-8715451d5a91',
            'domain' => 'reservations.grandpalace.com', 'type' => 'custom',
            'verified' => false, 'sslStatus' => 'pending',
        ], 201),
        dokweUrl2('/dokwe-cd-1/verify') => Http::response([
            'id' => 'dokwe-cd-1', 'projectId' => '9b22e799-a6eb-43db-841e-8715451d5a91',
            'domain' => 'reservations.grandpalace.com', 'type' => 'custom',
            'verified' => true, 'sslStatus' => 'provisioned',
        ], 200),
    ]);

    $tenant = Tenant::factory()->create([
        'custom_domain' => 'reservations.grandpalace.com',
    ]);

    (new AttachCustomDomainToDokwe($tenant->id))->handle(DokweClient::default());

    expect($tenant->refresh()->custom_domain_dokwe_id)->toBe('dokwe-cd-1');
});

it('exits cleanly when custom_domain was cleared between dispatch and run', function (): void {
    Http::fake();
    $tenant = Tenant::factory()->create(['custom_domain' => null]);

    (new AttachCustomDomainToDokwe($tenant->id))->handle(DokweClient::default());

    Http::assertNothingSent();
    expect($tenant->refresh()->custom_domain_dokwe_id)->toBeNull();
});

it('VerifyCustomDomain dispatches AttachCustomDomainToDokwe on success', function (): void {
    $tenant = Tenant::factory()->create([
        'custom_domain' => 'reservations.grandpalace.com',
        'custom_domain_verification_token' => 'kev_token_abc',
        'custom_domain_status' => CustomDomainStatus::Pending,
    ]);

    $dns = new FakeDnsResolver;
    $dns->withTxt('_centurion-verify.reservations.grandpalace.com', ['kev_token_abc']);

    Bus::fake([AttachCustomDomainToDokwe::class]);
    Http::fake();

    (new VerifyCustomDomain($tenant->id))->handle($dns, app(CheckOwnDomain::class));

    expect($tenant->refresh()->custom_domain_status)->toBe(CustomDomainStatus::Active);
    Bus::assertDispatched(AttachCustomDomainToDokwe::class,
        fn ($job) => $job->tenantId === $tenant->id);
});

it('does NOT dispatch AttachCustomDomainToDokwe when DNS check fails', function (): void {
    $tenant = Tenant::factory()->create([
        'custom_domain' => 'still-broken.com',
        'custom_domain_verification_token' => 'kev_token_xyz',
        'custom_domain_status' => CustomDomainStatus::Pending,
    ]);

    // DNS deliberately missing the TXT record → verify loop must NOT cascade.
    $dns = new FakeDnsResolver;

    Bus::fake([AttachCustomDomainToDokwe::class, VerifyCustomDomain::class]);

    (new VerifyCustomDomain($tenant->id))->handle($dns, app(CheckOwnDomain::class));

    Bus::assertNotDispatched(AttachCustomDomainToDokwe::class);
});

it('skips attach when custom_domain_dokwe_id is already set (idempotent)', function (): void {
    Http::fake([
        dokweUrl2('/already-cd-id/verify') => Http::response([
            'id' => 'already-cd-id', 'projectId' => '9b22e799-a6eb-43db-841e-8715451d5a91',
            'domain' => 'reservations.grandpalace.com', 'type' => 'custom',
            'verified' => true, 'sslStatus' => 'provisioned',
        ], 200),
    ]);

    $tenant = Tenant::factory()->create([
        'custom_domain' => 'reservations.grandpalace.com',
        'custom_domain_dokwe_id' => 'already-cd-id',
    ]);

    (new AttachCustomDomainToDokwe($tenant->id))->handle(DokweClient::default());

    Http::assertNotSent(fn ($req) => $req->method() === 'POST' && $req->url() === dokweUrl2());
    Http::assertSent(fn ($req) => str_contains($req->url(), '/already-cd-id/verify'));
});
