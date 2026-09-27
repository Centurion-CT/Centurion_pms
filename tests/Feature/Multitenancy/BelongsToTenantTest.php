<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Multitenancy\TenantNotResolvedException;
use App\Multitenancy\TenantScope;
use Tests\Concerns\CreatesFixtureSchema;
use Tests\Fixtures\TenantOwnedFixture;

uses(CreatesFixtureSchema::class);

beforeEach(function (): void {
    $this->createFixtureSchema();
});

afterEach(function (): void {
    // Reset Spatie's current-tenant container so one test doesn't leak into the next.
    if (Tenant::checkCurrent()) {
        Tenant::forgetCurrent();
    }
});

it('auto-populates tenant_id from the current tenant on create', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    $row = TenantOwnedFixture::create(['label' => 'Auto-assigned']);

    expect($row->tenant_id)->toBe($tenant->id);
});

it('refuses to create a tenant-owned model when no tenant is current', function (): void {
    expect(fn () => TenantOwnedFixture::create(['label' => 'orphan']))
        ->toThrow(TenantNotResolvedException::class);
});

it('scopes queries to the current tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantA->makeCurrent();
    TenantOwnedFixture::create(['label' => 'A-1']);
    TenantOwnedFixture::create(['label' => 'A-2']);

    $tenantB->makeCurrent();
    TenantOwnedFixture::create(['label' => 'B-1']);

    expect(TenantOwnedFixture::query()->pluck('label')->all())->toBe(['B-1']);

    $tenantA->makeCurrent();
    expect(TenantOwnedFixture::query()->pluck('label')->sort()->values()->all())
        ->toBe(['A-1', 'A-2']);
});

it('returns no rows when no tenant is current (fail-closed)', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    TenantOwnedFixture::create(['label' => 'has data']);

    Tenant::forgetCurrent();

    expect(TenantOwnedFixture::query()->count())->toBe(0);
});

it('allows opting out of the scope to query across tenants (admin path)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantA->makeCurrent();
    TenantOwnedFixture::create(['label' => 'A-row']);

    $tenantB->makeCurrent();
    TenantOwnedFixture::create(['label' => 'B-row']);

    Tenant::forgetCurrent();

    $allRows = TenantOwnedFixture::query()
        ->withoutGlobalScope(TenantScope::class)
        ->pluck('label')
        ->sort()
        ->values()
        ->all();

    expect($allRows)->toBe(['A-row', 'B-row']);
});

it('respects an explicit tenant_id even when a different tenant is current', function (): void {
    // Legitimate use: admin impersonation / one-off backfills.
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $tenantA->makeCurrent();

    $row = new TenantOwnedFixture(['label' => 'explicit']);
    $row->tenant_id = $tenantB->id;
    $row->save();

    expect($row->fresh()->tenant_id)->toBe($tenantB->id);
});

it('exposes the tenant() BelongsTo relationship', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    $row = TenantOwnedFixture::create(['label' => 'with rel']);

    expect($row->tenant)->toBeInstanceOf(Tenant::class);
    expect($row->tenant->id)->toBe($tenant->id);
});
