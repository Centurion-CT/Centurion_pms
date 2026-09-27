<?php

declare(strict_types=1);

use App\Actions\Bookings\MarkCheckedIn;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Settings\PropertyForm;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Bookings\LateCheckoutFeeCalculator;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Freeze the property's late-checkout policy on the booking at
 * check-in so a policy change tomorrow doesn't retroactively rewrite
 * the rules a guest was told today. Fee calculator prefers the
 * snapshot; existing bookings without one fall back to live property
 * config so nothing pre-migration breaks.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'policy-snap']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create([
        'house_rules' => ['check_out_to' => '11:30'],
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 10,
            'late_checkout_hourly_fee' => 5000,
            'late_checkout_full_day_after_hours' => 6,
            'early_checkout_default_mode' => 'ask',
            'early_checkout_refund_percent' => 100,
        ],
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();

    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('MarkCheckedIn snapshots the current checkout policy onto the booking', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    app(MarkCheckedIn::class)->handle($booking, $this->operator);
    $snapshot = $booking->fresh()->checkout_policy_snapshot;

    expect($snapshot)->toBeArray();
    expect($snapshot['late_checkout_hourly_fee'])->toBe(5000);
    expect($snapshot['check_out_to'])->toBe('11:30');
    expect($snapshot['snapshot_source'])->toBe('checkin');
});

it('changing property policy backfills in-house bookings with the OLD policy + logs audit', function (): void {
    // Guest checked in under the OLD policy (₦5,000/hr, 11:30 cutoff).
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::today(),
        // Simulate a pre-migration booking with no snapshot yet.
        'checkout_policy_snapshot' => null,
    ]);

    // Operator raises the hourly fee to ₦8,000. This should seal the
    // in-house guest with the ₦5,000 snapshot BEFORE the property row
    // updates.
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyForm::class)
        ->set('checkoutPolicy.late_checkout_hourly_fee', 8000)
        ->call('save');

    $snapshot = $booking->fresh()->checkout_policy_snapshot;
    expect($snapshot)->toBeArray();
    expect($snapshot['late_checkout_hourly_fee'])->toBe(5000); // OLD price locked
    expect($snapshot['snapshot_source'])->toBe('pre_change_backfill');

    // Property itself updated to the new price.
    expect($this->property->fresh()->checkout_policy['late_checkout_hourly_fee'])->toBe(8000);

    // Audit trail captured for accounting.
    $activity = \DB::table('activity_log')
        ->where('event', 'checkout_policy_changed')
        ->latest('id')->first();
    expect($activity)->not->toBeNull();
    $props = json_decode($activity->properties, true);
    expect($props['before']['late_checkout_hourly_fee'])->toBe(5000);
    expect($props['after']['late_checkout_hourly_fee'])->toBe(8000);
    expect($props['in_house_sealed'])->toBe(1);
});

it('changing policy does NOT reseal a booking that already has a snapshot', function (): void {
    $existingSnapshot = [
        'late_checkout_enabled' => true,
        'late_checkout_hourly_fee' => 3000,   // pre-existing "old-old" price
        'check_out_to' => '12:00',
        'snapshot_source' => 'checkin',
    ];
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkout_policy_snapshot' => $existingSnapshot,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyForm::class)
        ->set('checkoutPolicy.late_checkout_hourly_fee', 8000)
        ->call('save');

    // Original ₦3,000 snapshot untouched.
    expect($booking->fresh()->checkout_policy_snapshot['late_checkout_hourly_fee'])->toBe(3000);
});

it('LateCheckoutFeeCalculator prefers the booking snapshot over the live property policy', function (): void {
    // Bake the snapshot at ₦5,000/hr, then bump the live property to ₦8,000.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::today(),
        'checkout_policy_snapshot' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 10,
            'late_checkout_hourly_fee' => 5000,
            'late_checkout_full_day_after_hours' => 6,
            'check_out_to' => '11:30',
            'snapshot_source' => 'checkin',
        ],
    ]);
    $this->property->forceFill([
        'checkout_policy' => array_merge($this->property->checkout_policy, [
            'late_checkout_hourly_fee' => 8000,
        ]),
    ])->save();

    // 2 hours late = 1 grace-cleared hour + 1 within-hour = 2 started hours.
    // Deadline is 11:40 (11:30 + 10 grace). "Now" at 13:41 → 2h 1m late → ceil = 3 started hrs.
    Carbon::setTestNow(Carbon::today()->setTime(13, 41));

    $quote = app(LateCheckoutFeeCalculator::class)->quote(
        $booking->fresh(), $this->property->fresh(), Carbon::now(),
    );

    // 3 started hours × ₦5,000 snapshot = ₦15,000. NOT ₦24,000 (3 × 8,000 live).
    expect($quote['kind'])->toBe('hourly');
    expect($quote['amount'])->toBe(15_000);
    expect($quote['hours_late'])->toBe(3);
});

it('same-day late guest is flagged + projects a fee before check-out is run', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::today(),
        'checkout_policy_snapshot' => \App\Models\Booking::buildCheckoutPolicySnapshot($this->property),
    ]);

    Carbon::setTestNow(Carbon::today()->setTime(13, 41)); // ~2h past 11:40 deadline

    expect($booking->isSameDayLate(Carbon::now()))->toBeTrue();

    $projection = $booking->projectedLateCheckoutFee(Carbon::now());
    expect($projection['kind'])->toBe('hourly');
    expect($projection['amount'])->toBeGreaterThan(0);
});
