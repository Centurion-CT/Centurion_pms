<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Multitenancy\HostTenantFinder;
use Illuminate\Http\Request;

/*
 * Which business a host belongs to (docs/MULTITENANCY.md). phpunit runs
 * TENANCY_MODE=domains with CENTRAL_DOMAIN=keverna.test and
 * TENANT_DOMAIN_SUFFIX=.keverna.test.
 */

function findFor(string $host): ?Tenant
{
    return app(HostTenantFinder::class)->findForRequest(Request::create("http://{$host}/"));
}

it('finds a business by its subdomain', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'grandpalace']);

    expect(findFor('grandpalace.keverna.test')?->id)->toBe($tenant->id);
    expect(findFor('GrandPalace.keverna.test')?->id)->toBe($tenant->id);
});

it('finds a business by its verified own domain', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'centurion',
        'custom_domain' => 'centurionapartments.ng',
        'domain_verified_at' => now(),
    ]);

    expect(findFor('centurionapartments.ng')?->id)->toBe($tenant->id);
    expect(findFor('centurion.keverna.test')?->id)->toBe($tenant->id);
});

it('ignores an own domain that is not verified yet', function (): void {
    Tenant::factory()->create(['custom_domain' => 'book.grandpalace.ng', 'domain_verified_at' => null]);

    expect(findFor('book.grandpalace.ng'))->toBeNull();
});

it('binds no business on the platform host, www, or reserved subdomains', function (): void {
    Tenant::factory()->create(['slug' => 'console']);

    expect(findFor('keverna.test'))->toBeNull();
    expect(findFor('www.keverna.test'))->toBeNull();
    expect(findFor('console.keverna.test'))->toBeNull();
});

it('binds no business for unknown, nested or foreign hosts', function (): void {
    Tenant::factory()->create(['slug' => 'grandpalace']);

    expect(findFor('sunrise.keverna.test'))->toBeNull();
    expect(findFor('a.grandpalace.keverna.test'))->toBeNull();
    expect(findFor('grandpalace.example.com'))->toBeNull();
});

it('does not find archived businesses but does find paused ones', function (): void {
    Tenant::factory()->create(['slug' => 'gone', 'status' => TenantStatus::Archived]);
    $paused = Tenant::factory()->create(['slug' => 'paused', 'status' => TenantStatus::Locked]);

    expect(findFor('gone.keverna.test'))->toBeNull();
    expect(findFor('paused.keverna.test')?->id)->toBe($paused->id);
});

it('binds the first business on every host in single mode', function (): void {
    config(['keverna.tenancy_mode' => 'single']);
    $first = Tenant::factory()->create(['slug' => 'first']);
    Tenant::factory()->create(['slug' => 'second']);

    expect(findFor('second.keverna.test')?->id)->toBe($first->id);
    expect(findFor('anything.example.com')?->id)->toBe($first->id);
});

it('caches the answer on the request, and forget() clears it', function (): void {
    $request = Request::create('http://late.keverna.test/');
    $finder = app(HostTenantFinder::class);

    expect($finder->findForRequest($request))->toBeNull();

    $tenant = Tenant::factory()->create(['slug' => 'late']);
    expect($finder->findForRequest($request))->toBeNull();

    HostTenantFinder::forget($request);
    expect($finder->findForRequest($request)?->id)->toBe($tenant->id);
});
