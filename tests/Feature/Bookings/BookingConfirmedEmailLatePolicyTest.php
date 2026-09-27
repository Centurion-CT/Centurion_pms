<?php

declare(strict_types=1);

use App\Enums\EmailTemplateKey;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Email\EmailTemplateRenderer;

/**
 * The BookingConfirmed email carries the property's check-in /
 * check-out windows and the late-checkout policy in plain English.
 * When the operator hasn't configured any of them, the email still
 * reads cleanly — no orphan "( )" or " · " artifacts.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'confirm-mail']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'name' => 'The Ivy',
        'house_rules' => ['check_in_from' => '14:00', 'check_in_to' => '18:00', 'check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 40_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    $this->renderer = app(EmailTemplateRenderer::class);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('includes check-in / check-out windows + late-fee policy in the confirmation body', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Amara',
        'checkin_date' => '2027-03-15',
        'checkout_date' => '2027-03-17',
    ]);

    $rendered = $this->renderer->renderForBooking(
        key: EmailTemplateKey::BookingConfirmed,
        booking: $b->fresh(),
    );

    expect($rendered->body)->toContain('2:00 PM — 6:00 PM');
    expect($rendered->body)->toContain('until 11:00 AM');
    expect($rendered->body)->toContain('Late check-out: ₦5,000/hour past 11:00 AM (30-min grace).');
    expect($rendered->body)->toContain('Stay 6+ hours late');
});

it('omits the late-fee line and time separators cleanly when nothing is configured', function (): void {
    $this->property->forceFill([
        'house_rules' => null,
        'checkout_policy' => null,
    ])->save();

    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2027-03-15',
        'checkout_date' => '2027-03-17',
    ]);

    $rendered = $this->renderer->renderForBooking(
        key: EmailTemplateKey::BookingConfirmed,
        booking: $b->fresh(),
    );

    // No "Late check-out:" line at all, no orphan " · " after the date.
    expect($rendered->body)->not->toContain('Late check-out:');
    expect($rendered->body)->not->toMatch('/Check-in: .+ ·\s*$/m');
    expect($rendered->body)->not->toMatch('/Check-out: .+ ·\s*$/m');
    // Collapses to no more than one blank line in a row.
    expect($rendered->body)->not->toMatch('/\n{3,}/');
});

it('omits the late-fee line when the policy exists but is disabled', function (): void {
    $this->property->forceFill([
        'checkout_policy' => [
            'late_checkout_enabled' => false,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ])->save();

    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2027-03-15',
        'checkout_date' => '2027-03-17',
    ]);

    $rendered = $this->renderer->renderForBooking(
        key: EmailTemplateKey::BookingConfirmed,
        booking: $b->fresh(),
    );

    expect($rendered->body)->not->toContain('Late check-out:');
    // Still shows the times.
    expect($rendered->body)->toContain('until 11:00 AM');
});
