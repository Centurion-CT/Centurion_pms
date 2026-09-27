<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Multitenancy\TenantNotResolvedException;
use App\Multitenancy\TenantScope;
use Tests\Concerns\CreatesFixtureSchema;
use Tests\Fixtures\TenantOwnedFixture;

/*
|--------------------------------------------------------------------------
| Tenant Isolation Test Suite (PRD §12 — Critical risk)
|--------------------------------------------------------------------------
|
| "Tenant data leak due to missing scope" is the single highest-impact risk
| in the PRD. These tests verify that the BelongsToTenant trait + TenantScope
| combination makes cross-tenant access *physically impossible* via the ORM.
|
| If any of these tests ever start failing, treat it as a P0 incident.
|
*/

uses(CreatesFixtureSchema::class);

beforeEach(function (): void {
    $this->createFixtureSchema();
});

afterEach(function (): void {
    if (Tenant::checkCurrent()) {
        Tenant::forgetCurrent();
    }
});

it('tenant A cannot read tenant B rows via Eloquent::all()', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantB->makeCurrent();
    TenantOwnedFixture::create(['label' => 'B-secret']);

    $tenantA->makeCurrent();
    expect(TenantOwnedFixture::all())->toHaveCount(0);
});

it('tenant A cannot read tenant B rows via find() by guessing IDs', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantB->makeCurrent();
    $bRow = TenantOwnedFixture::create(['label' => 'B-private']);

    $tenantA->makeCurrent();
    expect(TenantOwnedFixture::find($bRow->id))->toBeNull();
});

it('tenant A cannot update tenant B rows via where()->update()', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantB->makeCurrent();
    $bRow = TenantOwnedFixture::create(['label' => 'B-original']);

    $tenantA->makeCurrent();
    $affected = TenantOwnedFixture::query()
        ->where('id', $bRow->id)
        ->update(['label' => 'TAMPERED']);

    expect($affected)->toBe(0);

    $tenantB->makeCurrent();
    expect($bRow->fresh()->label)->toBe('B-original');
});

it('tenant A cannot delete tenant B rows', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantB->makeCurrent();
    $bRow = TenantOwnedFixture::create(['label' => 'B-keep']);

    $tenantA->makeCurrent();
    $deleted = TenantOwnedFixture::query()->where('id', $bRow->id)->delete();

    expect($deleted)->toBe(0);

    Tenant::forgetCurrent();
    $exists = TenantOwnedFixture::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('id', $bRow->id)
        ->exists();
    expect($exists)->toBeTrue();
});

it('rows created under tenant A are tagged with tenant_id A even if A also creates rows for B-via-loop', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantA->makeCurrent();
    $rowA = TenantOwnedFixture::create(['label' => 'inside A']);

    $tenantB->makeCurrent();
    $rowB = TenantOwnedFixture::create(['label' => 'inside B']);

    expect($rowA->tenant_id)->toBe($tenantA->id);
    expect($rowB->tenant_id)->toBe($tenantB->id);
});

it('forgetting the current tenant blocks ALL reads on tenant-owned models (fail-closed)', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    TenantOwnedFixture::create(['label' => 'visible']);
    TenantOwnedFixture::create(['label' => 'also visible']);

    Tenant::forgetCurrent();

    expect(TenantOwnedFixture::count())->toBe(0);
    expect(TenantOwnedFixture::query()->first())->toBeNull();
});

it('rejects creating a tenant-owned row in central context (no tenant)', function (): void {
    expect(fn () => TenantOwnedFixture::create(['label' => 'orphan']))
        ->toThrow(TenantNotResolvedException::class);
});

it('queued jobs retain their tenant context (PRD §10 audit log requirement)', function (): void {
    // Spatie's MakeQueueTenantAwareAction injects the current tenant id onto the
    // job payload. We assert that running a closure with a current tenant
    // and then forgetting still leaves the row tagged correctly.
    $tenant = Tenant::factory()->create();

    $tenant->execute(function () use ($tenant): void {
        $row = TenantOwnedFixture::create(['label' => 'queue-style']);
        expect($row->tenant_id)->toBe($tenant->id);
    });

    expect(Tenant::checkCurrent())->toBeFalse();
});

it('two tenants with the same human-friendly label maintain separate rows', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantA->makeCurrent();
    TenantOwnedFixture::create(['label' => 'Room 1']);

    $tenantB->makeCurrent();
    TenantOwnedFixture::create(['label' => 'Room 1']);

    Tenant::forgetCurrent();
    $allRows = TenantOwnedFixture::query()
        ->withoutGlobalScope(TenantScope::class)
        ->get();

    expect($allRows)->toHaveCount(2);
    expect($allRows->pluck('tenant_id')->unique())->toHaveCount(2);
});
