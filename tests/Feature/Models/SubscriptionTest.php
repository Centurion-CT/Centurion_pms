<?php

declare(strict_types=1);

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

it('persists a subscription with all PRD §9 fields', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    $sub = Subscription::factory()->for($tenant)->create();

    expect($sub->plan)->toBe(SubscriptionPlan::Annual);
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->amount)->toBe(300_000);
    expect($sub->expires_at->isFuture())->toBeTrue();

    Tenant::forgetCurrent();
});

it('enforces uniqueness on paystack_reference', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    Subscription::factory()->for($tenant)->create(['paystack_reference' => 'ref_same']);

    expect(fn () => Subscription::factory()->for($tenant)->create(['paystack_reference' => 'ref_same']))
        ->toThrow(QueryException::class);

    Tenant::forgetCurrent();
});

it('treats trial + active as live, expired/failed as not', function (): void {
    expect(SubscriptionStatus::Trial->isLive())->toBeTrue();
    expect(SubscriptionStatus::Active->isLive())->toBeTrue();
    expect(SubscriptionStatus::Expired->isLive())->toBeFalse();
    expect(SubscriptionStatus::Failed->isLive())->toBeFalse();
});

it('isLive() returns false for expired-but-active rows (status active, expires_at past)', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    $sub = Subscription::factory()->for($tenant)->create([
        'status' => SubscriptionStatus::Active,
        'expires_at' => now()->subDay(),
    ]);

    expect($sub->isLive())->toBeFalse();

    Tenant::forgetCurrent();
});

it('exposes annual plan amount from config', function (): void {
    expect(SubscriptionPlan::Annual->amountNgn())->toBe(300_000);
    expect(SubscriptionPlan::Trial->amountNgn())->toBe(0);
});

it('exposes plan periodDays', function (): void {
    expect(SubscriptionPlan::Trial->periodDays())->toBe(14);
    expect(SubscriptionPlan::Annual->periodDays())->toBe(365);
});

it('is tenant-scoped: subscription created under tenant A is invisible to tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantA->makeCurrent();
    Subscription::factory()->for($tenantA)->create();

    $tenantB->makeCurrent();
    expect(Subscription::count())->toBe(0);

    Tenant::forgetCurrent();
});

it('exposes tenant->currentSubscription() returning the most recent live row', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    Subscription::factory()->for($tenant)->trial()->create([
        'expires_at' => now()->addDays(7),
    ]);
    $renewal = Subscription::factory()->for($tenant)->create([
        'expires_at' => now()->addYear(),
    ]);
    Subscription::factory()->for($tenant)->expired()->create();

    expect($tenant->currentSubscription()?->id)->toBe($renewal->id);

    Tenant::forgetCurrent();
});

it('returns null from currentSubscription when no live rows exist', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    Subscription::factory()->for($tenant)->expired()->create();
    Subscription::factory()->for($tenant)->failed()->create();

    expect($tenant->currentSubscription())->toBeNull();

    Tenant::forgetCurrent();
});
