<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Multitenancy\DomainTenantFinder;
use Illuminate\Http\Request;

function finderFor(string $host): ?Tenant
{
    $request = Request::create('http://'.$host.'/');
    /** @var Tenant|null $result */
    $result = (new DomainTenantFinder)->findForRequest($request);

    return $result;
}

it('resolves a tenant by its subdomain slug', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'maitama-suites']);

    expect(finderFor('maitama-suites.keverna.test')?->id)->toBe($tenant->id);
});

it('returns null for the central domain so central routes do not get a tenant', function (): void {
    Tenant::factory()->create(['slug' => 'whatever']);

    expect(finderFor('keverna.test'))->toBeNull();
});

it('returns null for the admin domain', function (): void {
    Tenant::factory()->create(['slug' => 'anything']);

    expect(finderFor('admin.keverna.test'))->toBeNull();
});

it('returns null when the subdomain matches no tenant', function (): void {
    expect(finderFor('does-not-exist.keverna.test'))->toBeNull();
});

it('returns null for nested subdomains (e.g. foo.bar.keverna.test)', function (): void {
    Tenant::factory()->create(['slug' => 'foo']);

    expect(finderFor('foo.bar.keverna.test'))->toBeNull();
});

it('resolves a tenant by its verified custom domain', function (): void {
    $tenant = Tenant::factory()
        ->withCustomDomain('reservations.grandpalace.com')
        ->create();

    expect(finderFor('reservations.grandpalace.com')?->id)->toBe($tenant->id);
});

it('does NOT resolve a custom domain that is unverified (domain_verified_at IS NULL)', function (): void {
    Tenant::factory()->create([
        'custom_domain' => 'pending.example.com',
        'domain_verified_at' => null,
    ]);

    expect(finderFor('pending.example.com'))->toBeNull();
});

it('refuses to resolve an archived tenant — its public surface should 404', function (): void {
    Tenant::factory()->archived()->create(['slug' => 'gone']);

    expect(finderFor('gone.keverna.test'))->toBeNull();
});

it('still resolves a locked tenant — needed to render the "temporarily unavailable" page', function (): void {
    $tenant = Tenant::factory()->locked()->create(['slug' => 'in-grace']);

    expect(finderFor('in-grace.keverna.test')?->id)->toBe($tenant->id);
});

it('is case-insensitive on host matching', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'mixed-case']);

    expect(finderFor('MIXED-CASE.KEVERNA.test')?->id)->toBe($tenant->id);
});

it('returns null for completely unrelated hosts (e.g. localhost)', function (): void {
    Tenant::factory()->create();

    expect(finderFor('localhost'))->toBeNull();
});
