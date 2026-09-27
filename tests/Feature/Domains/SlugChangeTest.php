<?php

declare(strict_types=1);

use App\Actions\Domains\ChangeSlug;
use App\Jobs\Domains\ProvisionTenantSubdomain;
use App\Models\SlugRedirect;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Queue::fake();
});

it('changes the slug, stamps slug_changed_at, and clears subdomain_provisioned_at', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'old-slug']);
    $tenant->forceFill(['subdomain_provisioned_at' => now()])->save();

    app(ChangeSlug::class)->handle($tenant, 'new-slug');

    $tenant->refresh();
    expect($tenant->slug)->toBe('new-slug');
    expect($tenant->slug_changed_at)->not->toBeNull();
    expect($tenant->subdomain_provisioned_at)->toBeNull();
});

it('creates a 90-day slug_redirect from the old slug to the tenant', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'old-slug-2']);

    app(ChangeSlug::class)->handle($tenant, 'fresh-slug');

    $redirect = SlugRedirect::query()->where('from_slug', 'old-slug-2')->first();
    expect($redirect)->not->toBeNull();
    expect($redirect->to_tenant_id)->toBe($tenant->id);
    expect($redirect->expires_at->diffInDays(now(), absolute: true))->toBeBetween(89.5, 90.5);
});

it('dispatches ProvisionTenantSubdomain so the new CNAME gets created', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'old-slug-3']);

    app(ChangeSlug::class)->handle($tenant, 'newly-named');

    Queue::assertPushed(ProvisionTenantSubdomain::class,
        fn ($job) => $job->tenantId === $tenant->id);
});

it('refuses to change the slug a second time within 365 days (PRD §6.6.1)', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'first-slug']);

    app(ChangeSlug::class)->handle($tenant, 'second-slug');

    Carbon::setTestNow(Carbon::now()->addDays(100));

    expect(fn () => app(ChangeSlug::class)->handle($tenant->fresh(), 'third-slug'))
        ->toThrow(ValidationException::class);
});

it('allows a slug change again 366+ days after the previous one', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'first-slug']);
    app(ChangeSlug::class)->handle($tenant, 'second-slug');

    Carbon::setTestNow(Carbon::now()->addDays(366));

    app(ChangeSlug::class)->handle($tenant->fresh(), 'third-slug');

    expect($tenant->fresh()->slug)->toBe('third-slug');
});

it('rejects a slug that is taken by another tenant', function (): void {
    Tenant::factory()->create(['slug' => 'shared']);
    $other = Tenant::factory()->create(['slug' => 'mine']);

    expect(fn () => app(ChangeSlug::class)->handle($other, 'shared'))
        ->toThrow(ValidationException::class);
});

it('rejects a slug that is currently held by an unexpired redirect (someone could still be linking it)', function (): void {
    // Seed an active redirect directly so we don't burn the cooldown on tenant b.
    $a = Tenant::factory()->create(['slug' => 'new']);
    SlugRedirect::query()->create([
        'from_slug' => 'old',
        'to_tenant_id' => $a->id,
        'expires_at' => Carbon::now()->addDays(60),
    ]);

    $b = Tenant::factory()->create(['slug' => 'something-else']);

    expect(fn () => app(ChangeSlug::class)->handle($b, 'old'))
        ->toThrow(ValidationException::class);
});

it('allows reusing a slug whose redirect has expired (>90 days)', function (): void {
    $a = Tenant::factory()->create(['slug' => 'old-reuse']);
    app(ChangeSlug::class)->handle($a, 'now-renamed');

    Carbon::setTestNow(Carbon::now()->addDays(91));

    $b = Tenant::factory()->create(['slug' => 'b-slug']);
    app(ChangeSlug::class)->handle($b, 'old-reuse');

    expect($b->fresh()->slug)->toBe('old-reuse');
});

it('is a no-op when the desired slug equals the current slug', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'unchanged']);

    app(ChangeSlug::class)->handle($tenant, 'unchanged');

    Queue::assertNotPushed(ProvisionTenantSubdomain::class);
    expect($tenant->fresh()->slug_changed_at)->toBeNull();
});

it('rejects an empty slug', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'x']);

    expect(fn () => app(ChangeSlug::class)->handle($tenant, '---'))
        ->toThrow(ValidationException::class);
});
