<?php

declare(strict_types=1);

use App\Enums\SubscriptionPlan;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Top-of-dashboard warning bar. Distinct from the post-expiry read-
 * only bar at the bottom — this fires BEFORE expiry so ops has runway
 * to route payment. Trials get a 5-day heads-up, annual plans get 14.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'expiry-warning']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders for a trial within the 5-day window', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->addDays(3),
    ]);

    $this->actingAs($this->operator, 'web')
        ->get('http://expiry-warning.keverna.test/dashboard')
        ->assertOk()
        ->assertSeeHtml('data-testid="expiry-warning-bar"')
        ->assertSee('Your trial ends in')
        ->assertSee('Upgrade');
});

it('does NOT render for a trial that has plenty of runway', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->addDays(10),
    ]);

    $this->actingAs($this->operator, 'web')
        ->get('http://expiry-warning.keverna.test/dashboard')
        ->assertOk()
        ->assertDontSeeHtml('data-testid="expiry-warning-bar"');
});

it('renders for a paid annual within the 14-day window', function (): void {
    Subscription::factory()->for($this->tenant)->create([
        'plan' => SubscriptionPlan::Annual,
        'status' => \App\Enums\SubscriptionStatus::Active,
        'expires_at' => Carbon::now()->addDays(10),
        'paid_at' => Carbon::now()->subMonths(11),
    ]);

    $this->actingAs($this->operator, 'web')
        ->get('http://expiry-warning.keverna.test/dashboard')
        ->assertOk()
        ->assertSeeHtml('data-testid="expiry-warning-bar"')
        ->assertSee('Your subscription renews in')
        ->assertSee('Renew now');
});

it('does NOT render for an annual with a month+ remaining', function (): void {
    Subscription::factory()->for($this->tenant)->create([
        'plan' => SubscriptionPlan::Annual,
        'status' => \App\Enums\SubscriptionStatus::Active,
        'expires_at' => Carbon::now()->addDays(30),
        'paid_at' => Carbon::now()->subMonths(11),
    ]);

    $this->actingAs($this->operator, 'web')
        ->get('http://expiry-warning.keverna.test/dashboard')
        ->assertOk()
        ->assertDontSeeHtml('data-testid="expiry-warning-bar"');
});

it('does NOT render once the sub has already expired (read-only bar owns that state)', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);

    $this->actingAs($this->operator, 'web')
        ->get('http://expiry-warning.keverna.test/dashboard')
        ->assertOk()
        ->assertDontSeeHtml('data-testid="expiry-warning-bar"');
});
