<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full Laravel app + reset the database between tests.
| Unit tests stay POPO so they're fast and stack-trace clean.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Seed + return a redeemable licence code so Register-based feature
 * tests can pass the gate without hand-rolling one every time.
 */
function issuedLicenseCode(array $overrides = []): string
{
    return \App\Models\LicenseCode::query()->create(array_merge([
        'code'   => \App\Models\LicenseCode::generateCode(),
        'status' => 'unused',
    ], $overrides))->code;
}

/**
 * Public landing URL for a tenant's first property. The root URL now
 * redirects to the staff sign-in, so landing tests use the branch page
 * (same controller). Falls back to the root when there is no property.
 */
function landingUrl(string $host, string $path = ''): string
{
    $slug = explode('.', $host)[0];
    $tenant = \App\Models\Tenant::query()->where('slug', $slug)->first();
    $property = $tenant === null ? null : \App\Models\Property::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->orderBy('id')
        ->first();

    return 'http://'.$host.($property === null ? '' : '/branches/'.$property->slug).'/'.$path;
}
