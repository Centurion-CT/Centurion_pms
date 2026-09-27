<?php

declare(strict_types=1);

use App\Models\SlugRedirect;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'rebranded',
        'name' => 'Rebranded Inn',
    ]);
    SlugRedirect::query()->create([
        'from_slug' => 'old-name',
        'to_tenant_id' => $this->tenant->id,
        'expires_at' => Carbon::now()->addDays(60),
    ]);
});

it('301-redirects requests to an old slug to the current slug, preserving path + query', function (): void {
    $response = $this->get('http://old-name.keverna.test/some/path?ref=email');

    $response->assertRedirect('http://rebranded.keverna.test/some/path?ref=email');
    $response->assertStatus(301);
});

it('does NOT redirect when the redirect has expired', function (): void {
    Carbon::setTestNow(Carbon::now()->addDays(91));

    $response = $this->get('http://old-name.keverna.test/');

    // No tenant matches `old-name` anymore + redirect expired → 404
    $response->assertNotFound();
});

it('falls through to the regular tenant resolver when host matches a current slug', function (): void {
    $response = $this->get('http://rebranded.keverna.test/');

    $response->assertOk();
    $response->assertSee('Rebranded Inn'); // landing-placeholder shows tenant->name
});

it('does NOT redirect requests on the central or admin domain', function (): void {
    $response = $this->get('http://keverna.test/');
    $response->assertOk();

    $admin = $this->get('http://admin.keverna.test/admin/__admin-ping');
    $admin->assertOk();
});
