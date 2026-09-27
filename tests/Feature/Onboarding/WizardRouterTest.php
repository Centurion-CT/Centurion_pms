<?php

declare(strict_types=1);

use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'wizard-routes']);
    $this->tenant->makeCurrent();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('routes a fresh operator with no property to step 1 (PropertyBasics)', function (): void {
    $operator = Operator::factory()->for($this->tenant)->unverified()->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/onboarding');

    $response->assertRedirect(route('tenant.onboarding.property'));
});

it('routes an operator with a property but no email verified to step 2', function (): void {
    $operator = Operator::factory()->for($this->tenant)->unverified()->create();
    Property::factory()->for($this->tenant)->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/onboarding');

    $response->assertRedirect(route('tenant.onboarding.email'));
});

it('routes an operator with property + email verified but no phone to step 3', function (): void {
    $operator = Operator::factory()
        ->for($this->tenant)
        ->unverified()
        ->create(['email_verified_at' => now()]);
    Property::factory()->for($this->tenant)->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/onboarding');

    $response->assertRedirect(route('tenant.onboarding.phone'));
});

it('routes a fully-onboarded operator to the dashboard', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create(); // verified by default
    Property::factory()->for($this->tenant)->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/onboarding');

    $response->assertRedirect(route('tenant.dashboard'));
});

it('the dashboard is hard-gated — unfinished onboarding redirects back to next step', function (): void {
    $operator = Operator::factory()->for($this->tenant)->unverified()->create();
    Property::factory()->for($this->tenant)->create();
    // email still null -> step 2 expected

    $response = $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/dashboard');

    $response->assertRedirect(route('tenant.onboarding.email'));
});

it('the subscription page is also gated by onboarding', function (): void {
    $operator = Operator::factory()->for($this->tenant)->unverified()->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/subscription');

    $response->assertRedirect(route('tenant.onboarding.property'));
});

it('a fully-onboarded operator can reach /dashboard', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create();
    Property::factory()->for($this->tenant)->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/dashboard');

    $response->assertOk();
    $response->assertSee($operator->name);
});

it('an operator who skipped the phone step reaches the dashboard', function (): void {
    // WhatsApp/Sendpipe escape hatch — skipped counts as resolved.
    $operator = Operator::factory()
        ->for($this->tenant)
        ->unverified()
        ->create([
            'email_verified_at' => now(),
            'phone_verification_skipped_at' => now(),
        ]);
    Property::factory()->for($this->tenant)->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/onboarding');

    $response->assertRedirect(route('tenant.dashboard'));

    $this->actingAs($operator, 'web')
        ->get('http://wizard-routes.keverna.test/dashboard')
        ->assertOk();
});
