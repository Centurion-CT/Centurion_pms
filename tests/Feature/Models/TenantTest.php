<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

it('persists a tenant with all PRD §9 fields', function (): void {
    $tenant = Tenant::factory()->create([
        'name' => 'Grand Palace Abuja',
        'slug' => 'grand-palace-abuja',
        'email' => 'gm@grandpalace.test',
    ]);

    expect($tenant->id)->toBeInt();
    expect($tenant->slug)->toBe('grand-palace-abuja');
    expect($tenant->status)->toBe(TenantStatus::Active);
});

it('enforces slug uniqueness at the database level', function (): void {
    Tenant::factory()->create(['slug' => 'taken-slug']);

    expect(fn () => Tenant::factory()->create(['slug' => 'taken-slug']))
        ->toThrow(QueryException::class);
});

it('encrypts paystack_secret_key at rest but exposes it decrypted on the model', function (): void {
    $tenant = Tenant::factory()->create(['paystack_secret_key' => 'sk_live_super_secret']);

    expect($tenant->paystack_secret_key)->toBe('sk_live_super_secret');

    $rawRow = DB::table('tenants')->where('id', $tenant->id)->first();
    expect($rawRow->paystack_secret_key)
        ->not->toBe('sk_live_super_secret')
        ->and(strlen($rawRow->paystack_secret_key))->toBeGreaterThan(50);
});

it('exposes status as a typed enum', function (): void {
    $tenant = Tenant::factory()->trial()->create();

    expect($tenant->status)->toBe(TenantStatus::Trial);
    expect($tenant->status->canAcceptBookings())->toBeTrue();
});

it('marks locked tenants as not accepting bookings', function (): void {
    $tenant = Tenant::factory()->locked()->create();

    expect($tenant->status)->toBe(TenantStatus::Locked);
    expect($tenant->status->canAcceptBookings())->toBeFalse();
});

it('uses slug as its route key', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'lekki-suites']);

    expect($tenant->getRouteKeyName())->toBe('slug');
    expect($tenant->getRouteKey())->toBe('lekki-suites');
});

it('computes primaryHost from the subdomain by default', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'maitama-apartments']);

    expect($tenant->primaryHost())->toBe('maitama-apartments.keverna.test');
});

it('prefers a verified custom domain for primaryHost', function (): void {
    $tenant = Tenant::factory()
        ->withCustomDomain('reservations.grandpalace.com')
        ->create(['slug' => 'grand-palace']);

    expect($tenant->primaryHost())->toBe('reservations.grandpalace.com');
});

it('keeps primaryHost on the subdomain until the own domain is live', function (): void {
    $tenant = Tenant::factory()->withCustomDomain('book.grandpalace.com')
        ->create(['slug' => 'grand-palace', 'custom_domain_live_at' => null]);

    expect($tenant->primaryHost())->toBe('grand-palace.keverna.test');
});

it('ignores an unverified custom domain for primaryHost', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'pending-tenant',
        'custom_domain' => 'pending.example.com',
        'domain_verified_at' => null,
    ]);

    expect($tenant->primaryHost())->toBe('pending-tenant.keverna.test');
});

it('soft-deletes archived tenants so data is retained per PRD §4.2', function (): void {
    $tenant = Tenant::factory()->create();
    $id = $tenant->id;

    $tenant->delete();

    expect(Tenant::find($id))->toBeNull();
    expect(Tenant::withTrashed()->find($id))->not->toBeNull();
});

it('scopes operational tenants (trial|active|grace) via Operational scope', function (): void {
    Tenant::factory()->create();
    Tenant::factory()->trial()->create();
    Tenant::factory()->locked()->create();
    Tenant::factory()->archived()->create();

    $operational = Tenant::query()->operational()->get();

    expect($operational)->toHaveCount(2);
    expect($operational->pluck('status')->all())
        ->toContain(TenantStatus::Active)
        ->toContain(TenantStatus::Trial);
});
