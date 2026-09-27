<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

it('moves an Active tenant whose expiry passed into Grace', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Active,
        'subscription_expires_at' => Carbon::now()->subHours(2),
    ]);

    $this->artisan('subscriptions:process-expired')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Grace);
});

it('moves a Trial tenant whose 14-day window passed into Grace', function (): void {
    $tenant = Tenant::factory()->trial()->create([
        'subscription_expires_at' => Carbon::now()->subDay(),
    ]);

    $this->artisan('subscriptions:process-expired')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Grace);
});

it('does NOT move an Active tenant whose expiry is still in the future', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Active,
        'subscription_expires_at' => Carbon::now()->addDays(5),
    ]);

    $this->artisan('subscriptions:process-expired')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('moves a Grace tenant to Locked after the 3-day grace period', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Grace,
        'subscription_expires_at' => Carbon::now()->subDays(4),
    ]);

    $this->artisan('subscriptions:process-expired')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Locked);
});

it('keeps a Grace tenant in Grace within the 3-day window', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Grace,
        'subscription_expires_at' => Carbon::now()->subDay(),
    ]);

    $this->artisan('subscriptions:process-expired')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Grace);
});

it('moves a Locked tenant to Archived after 30 additional days', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Locked,
        'subscription_expires_at' => Carbon::now()->subDays(40),
    ]);

    $this->artisan('subscriptions:process-expired')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Archived);
});

it('keeps a Locked tenant in Locked within the 30-day window', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Locked,
        // expired 10 days ago: 10 > 3 (grace) but 10 < 33 (grace + lock)
        'subscription_expires_at' => Carbon::now()->subDays(10),
    ]);

    $this->artisan('subscriptions:process-expired')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Locked);
});

it('progresses a tenant through Active -> Grace -> Locked -> Archived via time travel', function (): void {
    $expires = Carbon::create(2026, 7, 1, 12);
    Carbon::setTestNow($expires->copy()->subDay());

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Active,
        'subscription_expires_at' => $expires,
    ]);

    // Day before expiry — no change.
    $this->artisan('subscriptions:process-expired');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);

    // Right after expiry — Grace.
    Carbon::setTestNow($expires->copy()->addHour());
    $this->artisan('subscriptions:process-expired');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Grace);

    // 4 days after — Locked (grace = 3d).
    Carbon::setTestNow($expires->copy()->addDays(4));
    $this->artisan('subscriptions:process-expired');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Locked);

    // 34 days after — Archived (grace + lock = 33d).
    Carbon::setTestNow($expires->copy()->addDays(34));
    $this->artisan('subscriptions:process-expired');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Archived);
});

it('does not move tenants with no expiry set (defensive)', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Active,
        'subscription_expires_at' => null,
    ]);

    $this->artisan('subscriptions:process-expired')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('is idempotent — re-running on the same day is a no-op', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Active,
        'subscription_expires_at' => Carbon::now()->subHour(),
    ]);

    $this->artisan('subscriptions:process-expired');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Grace);

    $this->artisan('subscriptions:process-expired');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Grace);
});
