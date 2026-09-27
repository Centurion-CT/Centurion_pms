<?php

declare(strict_types=1);

use App\Actions\Bookings\MarkCheckedOut;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Integration side of the late-checkout fee: given a property with
 * the policy enabled + a guest checking out same-day past the
 * check_out_to window, MarkCheckedOut writes a LateCheckout charge
 * and the outstanding-guard blocks completion until it's settled.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'late-fee-checkout']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'house_rules' => ['check_out_to' => '11:00'],
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
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('adds a hourly LateCheckout charge and blocks completion via outstanding guard', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 13:15'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-14',
        'checkout_date' => '2030-06-15',
    ]);

    expect(fn () => app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator))
        ->toThrow(RuntimeException::class, 'Late check-out fee added');

    $charge = BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', BookingChargeType::LateCheckout->value)
        ->firstOrFail();

    // deadline = 11:30, now = 13:15 → 105 min → ceil to 2 hours @ ₦5,000 = ₦10,000.
    expect((int) $charge->amount)->toBe(10_000);
    expect((bool) $charge->settled)->toBeFalse();
});

it('trips a full-day LateCheckout charge at the configured threshold', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 17:30'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-14',
        'checkout_date' => '2030-06-15',
    ]);

    expect(fn () => app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator))
        ->toThrow(RuntimeException::class);

    $charge = BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', BookingChargeType::LateCheckout->value)
        ->firstOrFail();

    // 17:30 is 6h past the 11:30 deadline → trips full-day → room base_rate.
    expect((int) $charge->amount)->toBe(40_000);
});

it('is idempotent — a re-run does NOT stack a second LateCheckout row', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 13:15'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-14',
        'checkout_date' => '2030-06-15',
    ]);

    try { app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator); } catch (\Throwable) {}
    // Operator retries later without settling — should NOT add another row.
    Carbon::setTestNow(Carbon::parse('2030-06-15 15:00'));
    try { app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator); } catch (\Throwable) {}

    expect(BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', BookingChargeType::LateCheckout->value)
        ->count())->toBe(1);
});

it('does NOT add a late-checkout charge when the policy is disabled', function (): void {
    $this->property->forceFill([
        'checkout_policy' => [
            'late_checkout_enabled' => false,
            'late_checkout_grace_minutes' => 30,
            'late_checkout_hourly_fee' => 5_000,
            'late_checkout_full_day_after_hours' => 6,
        ],
    ])->save();

    Carbon::setTestNow(Carbon::parse('2030-06-15 14:00'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-14',
        'checkout_date' => '2030-06-15',
    ]);

    app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator);

    expect($b->fresh()->booking_status)->toBe(BookingStatus::CheckedOut);
    expect(BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', BookingChargeType::LateCheckout->value)
        ->count())->toBe(0);
});
