<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\Tenant;

/**
 * The tenant landing shows a compact "Good to know" band with check-in
 * time, check-out time, and (when configured) the late-checkout fee
 * policy in plain English. The band only renders when at least one of
 * the three has content, so a bare property stays clean.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'late-fee-landing']);
});

it('shows the late-checkout fee tile when the policy has an hourly rate', function (): void {
    Property::factory()->live()->for($this->tenant)->create([
        'name' => 'Late Fee Inn',
        'house_rules' => ['check_in_from' => '14:00', 'check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ]);

    $response = $this->get('http://late-fee-landing.keverna.test/');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="good-to-know"');
    $response->assertSeeHtml('data-testid="gtk-late-fee"');
    $response->assertSee('₦5,000/hour past 11:00');
    $response->assertSee('30-min grace');
    $response->assertSee('6+ hours late');
});

it('hides the fee tile when the policy is disabled but still shows times', function (): void {
    Property::factory()->live()->for($this->tenant)->create([
        'name' => 'No Late Fee',
        'house_rules' => ['check_in_from' => '14:00', 'check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => false,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ]);

    $response = $this->get('http://late-fee-landing.keverna.test/');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="good-to-know"');
    $response->assertSeeHtml('data-testid="gtk-checkin"');
    $response->assertSeeHtml('data-testid="gtk-checkout"');
    $response->assertDontSeeHtml('data-testid="gtk-late-fee"');
});

it('hides the entire band when neither times nor a fee are set', function (): void {
    Property::factory()->live()->for($this->tenant)->create([
        'name' => 'Bare Property',
        'house_rules' => null,
        'checkout_policy' => null,
    ]);

    $response = $this->get('http://late-fee-landing.keverna.test/');

    $response->assertOk();
    $response->assertDontSeeHtml('data-testid="good-to-know"');
});

it('renders the band with just the fee tile when only the policy is set (no times)', function (): void {
    Property::factory()->live()->for($this->tenant)->create([
        'name' => 'Policy Only',
        'house_rules' => null,
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 0,
            'late_checkout_hourly_fee' => 3_000,
            'late_checkout_full_day_after_hours' => 0,
        ],
    ]);

    $response = $this->get('http://late-fee-landing.keverna.test/');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="good-to-know"');
    $response->assertSeeHtml('data-testid="gtk-late-fee"');
    $response->assertSee('₦3,000/hour');
});
