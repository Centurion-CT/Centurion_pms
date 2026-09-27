<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;

/**
 * Category detail carries the same "Good to know" block as the room
 * detail — check-in/out times + the late-checkout fee in plain
 * English. Same self-hiding rules.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'cd-gtk',
        'paystack_secret_key' => 'sk_test_key',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create([
        'house_rules' => ['check_in_from' => '14:00', 'check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 30_000,
    ]);
    // Category detail needs at least one bookable room to render fully.
    Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('shows check-in, check-out, and the late-fee tile on the category detail page', function (): void {
    $response = $this->get('http://cd-gtk.keverna.test/categories/'.$this->category->slug);

    $response->assertOk();
    $response->assertSeeHtml('data-testid="category-good-to-know"');
    $response->assertSeeHtml('data-testid="cd-gtk-checkin"');
    $response->assertSeeHtml('data-testid="cd-gtk-checkout"');
    $response->assertSeeHtml('data-testid="cd-gtk-late-fee"');
    $response->assertSee('₦5,000/hour past 11:00');
    $response->assertSee('30-min grace');
    $response->assertSee('6+ hours late');
});

it('hides the late-fee tile when the policy is disabled', function (): void {
    $this->property->forceFill([
        'checkout_policy' => [
            'late_checkout_enabled' => false,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ])->save();

    $response = $this->get('http://cd-gtk.keverna.test/categories/'.$this->category->slug);

    $response->assertOk();
    $response->assertSeeHtml('data-testid="category-good-to-know"');
    $response->assertDontSeeHtml('data-testid="cd-gtk-late-fee"');
});

it('hides the whole section when nothing is configured', function (): void {
    $this->property->forceFill([
        'house_rules' => null,
        'checkout_policy' => null,
    ])->save();

    $response = $this->get('http://cd-gtk.keverna.test/categories/'.$this->category->slug);

    $response->assertOk();
    $response->assertDontSeeHtml('data-testid="category-good-to-know"');
});
