<?php

declare(strict_types=1);

use App\Jobs\Domains\AttachTenantDomainToDokwe;
use App\Models\Tenant;
use App\Services\Dokwe\DokweClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('dokwe.base_url', 'https://dokwe.test/api');
    config()->set('dokwe.api_key', 'dk_pak_test');
    config()->set('dokwe.project_id', '9b22e799-a6eb-43db-841e-8715451d5a91');
    config()->set('keverna.tenant_domain_suffix', '.keverna.test');
    // Existing scenarios exercise the legacy dokwe-attach path. Prod is on
    // 'wildcard' now (see keverna.subdomain_provisioning.mode) which is
    // covered by a dedicated test below.
    config()->set('keverna.subdomain_provisioning.mode', 'dokwe-attach');
});

it('no-ops in wildcard mode without touching Dokwe', function (): void {
    config()->set('keverna.subdomain_provisioning.mode', 'wildcard');
    Http::fake(); // any request to Dokwe would be a test failure

    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    (new AttachTenantDomainToDokwe($tenant->id))->handle(DokweClient::default());

    Http::assertNothingSent();
    expect($tenant->fresh()->subdomain_dokwe_id)->toBeNull();
});

function dokweUrl(string $path = ''): string
{
    return 'https://dokwe.test/api/domains/9b22e799-a6eb-43db-841e-8715451d5a91'.$path;
}

it('attaches the {slug}.keverna fqdn and stamps subdomain_dokwe_id', function (): void {
    Http::fake([
        dokweUrl() => Http::response([
            'id' => 'dokwe-id-1',
            'projectId' => '9b22e799-a6eb-43db-841e-8715451d5a91',
            'domain' => 'acme.keverna.test',
            'type' => 'custom', 'verified' => false, 'sslStatus' => 'pending',
        ], 201),
        dokweUrl('/dokwe-id-1/verify') => Http::response([
            'id' => 'dokwe-id-1',
            'projectId' => '9b22e799-a6eb-43db-841e-8715451d5a91',
            'domain' => 'acme.keverna.test',
            'type' => 'custom', 'verified' => true, 'sslStatus' => 'provisioned',
        ], 200),
    ]);

    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    (new AttachTenantDomainToDokwe($tenant->id))->handle(DokweClient::default());

    expect($tenant->refresh()->subdomain_dokwe_id)->toBe('dokwe-id-1');
});

it('throws on verified=false so the queue retries through DNS propagation', function (): void {
    Http::fake([
        dokweUrl() => Http::response(['id' => 'dokwe-id-2'], 201),
        dokweUrl('/dokwe-id-2/verify') => Http::response([
            'id' => 'dokwe-id-2', 'projectId' => '9b22e799-a6eb-43db-841e-8715451d5a91',
            'domain' => 'pending.keverna.test', 'type' => 'custom',
            'verified' => false, 'sslStatus' => 'pending',
        ], 200),
    ]);

    $tenant = Tenant::factory()->create(['slug' => 'pending']);

    $job = new AttachTenantDomainToDokwe($tenant->id);

    expect(fn () => $job->handle(DokweClient::default()))
        ->toThrow(RuntimeException::class, 'not yet verified');

    expect($tenant->refresh()->subdomain_dokwe_id)->toBe('dokwe-id-2');
});

it('skips attach when subdomain_dokwe_id is already set (idempotent)', function (): void {
    Http::fake([
        dokweUrl('/already-id/verify') => Http::response([
            'id' => 'already-id', 'projectId' => '9b22e799-a6eb-43db-841e-8715451d5a91',
            'domain' => 'already.keverna.test', 'type' => 'custom',
            'verified' => true, 'sslStatus' => 'provisioned',
        ], 200),
    ]);

    $tenant = Tenant::factory()->create([
        'slug' => 'already',
        'subdomain_dokwe_id' => 'already-id',
    ]);

    (new AttachTenantDomainToDokwe($tenant->id))->handle(DokweClient::default());

    // No POST /domains call should have been issued — only the verify.
    Http::assertNotSent(fn ($req) => $req->method() === 'POST' && $req->url() === dokweUrl());
    Http::assertSent(fn ($req) => str_contains($req->url(), '/already-id/verify'));
});

it('exits cleanly when the tenant has been deleted', function (): void {
    Http::fake();
    (new AttachTenantDomainToDokwe(999_999_999))->handle(DokweClient::default());
    Http::assertNothingSent();
});

it('is dispatched by ProvisionTenantSubdomain after the subdomain is stamped', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'chained']);

    Bus::fake([AttachTenantDomainToDokwe::class]);

    // Wildcard DNS model — the provisioning job no longer takes a
    // CloudflareDnsService dependency; it just stamps and dispatches.
    (new \App\Jobs\Domains\ProvisionTenantSubdomain($tenant->id))->handle();

    Bus::assertDispatched(AttachTenantDomainToDokwe::class,
        fn ($job) => $job->tenantId === $tenant->id);
});
