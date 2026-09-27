<?php

declare(strict_types=1);

use App\Models\Tenant;

it('a subdomain that matches a tenant resolves and serves tenant routes', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'lekki-suites']);

    $response = $this->get('http://lekki-suites.keverna.test/__tenant-ping');

    $response->assertOk();
    $response->assertJson([
        'tenant_id' => $tenant->id,
        'slug' => 'lekki-suites',
        'host' => 'lekki-suites.keverna.test',
    ]);
});

it('a subdomain with no matching tenant returns 404', function (): void {
    $response = $this->get('http://ghost-tenant.keverna.test/__tenant-ping');

    $response->assertNotFound();
});

it('central domain serves the home page and does NOT serve tenant routes', function (): void {
    Tenant::factory()->create(['slug' => 'has-data']);

    $response = $this->get('http://keverna.test/');
    $response->assertOk();

    $tenantResp = $this->get('http://keverna.test/__tenant-ping');
    $tenantResp->assertNotFound();
});

it('admin domain serves admin routes and does NOT bind a tenant', function (): void {
    Tenant::factory()->create(['slug' => 'something']);

    $response = $this->get('http://admin.keverna.test/admin/__admin-ping');

    $response->assertOk();
    $response->assertJson(['admin' => true, 'host' => 'admin.keverna.test']);

    // Calling Tenant::current() here would not be valid after the response,
    // but we can assert that the test client never bound a tenant for this host.
    expect(Tenant::checkCurrent())->toBeFalse();
});

it('archived tenants return 404 on tenant routes (data retained but surface offline)', function (): void {
    Tenant::factory()->archived()->create(['slug' => 'archived-co']);

    $response = $this->get('http://archived-co.keverna.test/__tenant-ping');

    $response->assertNotFound();
});

it('a verified custom domain serves tenant routes', function (): void {
    $tenant = Tenant::factory()
        ->withCustomDomain('reservations.grandpalace.com')
        ->create(['slug' => 'grand-palace']);

    $response = $this->get('http://reservations.grandpalace.com/__tenant-ping');

    $response->assertOk();
    $response->assertJson(['tenant_id' => $tenant->id]);
});

it('an unverified custom domain returns 404', function (): void {
    Tenant::factory()->create([
        'slug' => 'pending',
        'custom_domain' => 'pending.example.com',
        'domain_verified_at' => null,
    ]);

    $response = $this->get('http://pending.example.com/__tenant-ping');

    $response->assertNotFound();
});

it('a locked tenant is still resolved so the "temporarily unavailable" page can render', function (): void {
    $tenant = Tenant::factory()->locked()->create(['slug' => 'paused']);

    $response = $this->get('http://paused.keverna.test/__tenant-ping');

    // The ping route doesn't check status — that's the landing page's job.
    // We only assert that a locked tenant DOES resolve through routing.
    $response->assertOk();
    $response->assertJson(['tenant_id' => $tenant->id]);
});
