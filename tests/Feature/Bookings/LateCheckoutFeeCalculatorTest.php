<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Bookings\LateCheckoutFeeCalculator;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'late-fee-calc']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 40_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    $this->calculator = app(LateCheckoutFeeCalculator::class);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function makeSameDayBooking(): Booking
{
    return Booking::factory()->for(test()->tenant)->for(test()->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-14',
        'checkout_date' => '2030-06-15',
    ]);
}

it('returns none when policy is disabled', function (): void {
    $this->property->forceFill([
        'house_rules' => ['check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => false,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ])->save();

    $b = makeSameDayBooking();
    $q = $this->calculator->quote($b, $this->property->fresh(), Carbon::parse('2030-06-15 14:00'));

    expect($q['kind'])->toBe('none');
    expect($q['amount'])->toBe(0);
});

it('returns none when the guest is still inside the grace window', function (): void {
    $this->property->forceFill([
        'house_rules' => ['check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 0,
        ],
    ])->save();

    $b = makeSameDayBooking();
    // 11:20 is 20 minutes past 11:00 → inside the 30-min grace.
    $q = $this->calculator->quote($b, $this->property->fresh(), Carbon::parse('2030-06-15 11:20'));

    expect($q['kind'])->toBe('none');
});

it('bills hourly per started hour past check_out_to + grace', function (): void {
    $this->property->forceFill([
        'house_rules' => ['check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 0, // never trip full-day
        ],
    ])->save();

    $b = makeSameDayBooking();
    // deadline = 11:30. Now = 13:15 → 105 minutes late → ceil to 2h.
    $q = $this->calculator->quote($b, $this->property->fresh(), Carbon::parse('2030-06-15 13:15'));

    expect($q['kind'])->toBe('hourly');
    expect($q['hours_late'])->toBe(2);
    expect($q['amount'])->toBe(10_000);
    expect($q['description'])->toContain('11:00');
    expect($q['description'])->toContain('₦5,000/hr');
});

it('trips the full-day charge when hours late meets the threshold', function (): void {
    $this->property->forceFill([
        'house_rules' => ['check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 0,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ])->save();

    $b = makeSameDayBooking();
    // deadline = 11:00. Now = 17:00 → exactly 6h late → trips full-day.
    $q = $this->calculator->quote($b, $this->property->fresh(), Carbon::parse('2030-06-15 17:00'));

    expect($q['kind'])->toBe('full_day');
    // Full-day uses room.base_rate, not hourly × threshold.
    expect($q['amount'])->toBe(40_000);
    expect($q['description'])->toContain('full-day rate');
});

it('does NOT charge when the guest is checking out on the wrong day (overstay territory)', function (): void {
    // Overstay is handled by MarkCheckedOut's nightly path; the
    // late-checkout calculator deliberately no-ops when today !=
    // checkout_date so we never double-count.
    $this->property->forceFill([
        'house_rules' => ['check_out_to' => '11:00'],
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 0,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ])->save();

    $b = makeSameDayBooking();
    // Guest is a day late — today is 2030-06-16 but checkout_date is 2030-06-15.
    $q = $this->calculator->quote($b, $this->property->fresh(), Carbon::parse('2030-06-16 09:00'));

    expect($q['kind'])->toBe('none');
});

it('returns none when the property has no check_out_to configured', function (): void {
    $this->property->forceFill([
        'house_rules' => [], // no check_out_to
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 0,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ])->save();

    $b = makeSameDayBooking();
    $q = $this->calculator->quote($b, $this->property->fresh(), Carbon::parse('2030-06-15 18:00'));

    expect($q['kind'])->toBe('none');
});
