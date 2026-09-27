<?php

declare(strict_types=1);

use App\Events\SubscriptionActivated;
use App\Events\TenantOnboarded;
use App\Jobs\Domains\AttachTenantDomainToDokwe;
use App\Jobs\Domains\ProvisionTenantSubdomain;
use App\Models\Operator;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;

/**
 * ProvisionTenantSubdomain no longer creates a per-tenant DNS record —
 * DNS is served by a single wildcard (`*.keverna.com`). The job's job
 * is now to stamp `subdomain_provisioned_at` and — in legacy dokwe-attach
 * mode only — hand off to Dokwe.
 */

it('stamps subdomain_provisioned_at and dispatches AttachTenantDomainToDokwe in dokwe-attach mode', function (): void {
    config()->set('keverna.subdomain_provisioning.mode', 'dokwe-attach');
    Bus::fake([AttachTenantDomainToDokwe::class]);

    $tenant = Tenant::factory()->create(['slug' => 'demo']);

    (new ProvisionTenantSubdomain($tenant->id))->handle();

    expect($tenant->fresh()->subdomain_provisioned_at)->not->toBeNull();
    Bus::assertDispatched(AttachTenantDomainToDokwe::class,
        fn ($job) => $job->tenantId === $tenant->id);
});

it('stamps subdomain_provisioned_at but does NOT dispatch attach in wildcard mode', function (): void {
    config()->set('keverna.subdomain_provisioning.mode', 'wildcard');
    Bus::fake([AttachTenantDomainToDokwe::class]);

    $tenant = Tenant::factory()->create(['slug' => 'demo']);

    (new ProvisionTenantSubdomain($tenant->id))->handle();

    expect($tenant->fresh()->subdomain_provisioned_at)->not->toBeNull();
    Bus::assertNotDispatched(AttachTenantDomainToDokwe::class);
});

it('is idempotent — a second run does not re-stamp or re-dispatch', function (): void {
    config()->set('keverna.subdomain_provisioning.mode', 'dokwe-attach');
    Bus::fake([AttachTenantDomainToDokwe::class]);

    $tenant = Tenant::factory()->create(['slug' => 'demo']);

    (new ProvisionTenantSubdomain($tenant->id))->handle();
    $firstStamp = $tenant->fresh()->subdomain_provisioned_at;

    (new ProvisionTenantSubdomain($tenant->id))->handle();
    $secondStamp = $tenant->fresh()->subdomain_provisioned_at;

    // Same timestamp on both runs — no re-stamp.
    expect($secondStamp->equalTo($firstStamp))->toBeTrue();

    // Only one Dokwe attach dispatch across both runs; the second run
    // short-circuits on the existing stamp. (Even without this short
    // circuit AttachTenantDomainToDokwe is ShouldBeUnique on tenant_id,
    // so the queue would collapse duplicates — belt-and-braces.)
    Bus::assertDispatchedTimes(AttachTenantDomainToDokwe::class, 1);
});

it('skips silently when the tenant was deleted between dispatch and run', function (): void {
    Bus::fake();

    (new ProvisionTenantSubdomain(999_999))->handle();

    Bus::assertNotDispatched(AttachTenantDomainToDokwe::class);
});

it('is dispatched on TenantOnboarded', function (): void {
    Bus::fake();
    $tenant = Tenant::factory()->create();
    $operator = Operator::factory()->for($tenant)->create();

    TenantOnboarded::dispatch($tenant, $operator);

    Bus::assertDispatched(ProvisionTenantSubdomain::class,
        fn ($job) => $job->tenantId === $tenant->id);
});

it('is dispatched on SubscriptionActivated', function (): void {
    Bus::fake();
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $sub = Subscription::factory()->for($tenant)->create();
    Tenant::forgetCurrent();

    SubscriptionActivated::dispatch($sub);

    Bus::assertDispatched(ProvisionTenantSubdomain::class,
        fn ($job) => $job->tenantId === $tenant->id);
});

it('exposes uniqueId() = tenant id so concurrent dispatches collapse', function (): void {
    $tenant = Tenant::factory()->create();
    $job = new ProvisionTenantSubdomain($tenant->id);

    expect($job->uniqueId())->toBe((string) $tenant->id);
});

it('declares tries=3 with exponential backoff', function (): void {
    $job = new ProvisionTenantSubdomain(1);

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([10, 30, 60]);
});
