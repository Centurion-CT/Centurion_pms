<?php

declare(strict_types=1);

use App\Actions\Bookings\ExtendBooking;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\ManualDiscountType;
use App\Enums\OperatorRole;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Extending a discounted stay: if the original booking was placed
 * with a percent manual discount, the extension nights must be
 * priced at the same discounted rate. Fixed-amount discounts do NOT
 * carry — those are one-off credits on the original total, not a
 * rate that scales with more nights.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'extend-discount']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 80_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create();
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('applies the original percent discount to the extension charge', function (): void {
    // Booked with 30% off — guest paid ₦56k/night on a ₦80k room
    // for 1 night, spent ₦56k total.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'total_amount' => 56_000, 'room_total' => 56_000,
        'balance_due' => 0,
        'manual_discount_type' => ManualDiscountType::Percent,
        'manual_discount_percent' => 30.0,
        'manual_discount_amount' => 24_000,
    ]);

    $booking = app(ExtendBooking::class)->handle(
        $booking,
        Carbon::today()->addDay(),
        $this->operator,
    );

    // 1 extra night at rack ₦80k, with 30% off → ₦56k charged.
    $extension = BookingCharge::query()
        ->where('booking_id', $booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->first();

    expect($extension)->not->toBeNull();
    expect((int) $extension->amount)->toBe(56_000);
    expect($extension->description)->toContain('30% off inherited');

    // Discount trail on the booking itself grows by the inherited
    // amount so /finance's "You saved" still totals correctly.
    expect((int) $booking->manual_discount_amount)->toBe(24_000 + 24_000);
});

it('does NOT inherit a fixed-amount discount to the extension', function (): void {
    // Booked with ₦20k flat off — that's a one-time credit, NOT a
    // rate. The extension should charge the full ₦80k rack.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'total_amount' => 60_000, 'room_total' => 60_000,
        'balance_due' => 0,
        'manual_discount_type' => ManualDiscountType::Fixed,
        'manual_discount_percent' => 0.0,
        'manual_discount_amount' => 20_000,
    ]);

    $booking = app(ExtendBooking::class)->handle(
        $booking,
        Carbon::today()->addDay(),
        $this->operator,
    );

    $extension = BookingCharge::query()
        ->where('booking_id', $booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->first();

    expect((int) $extension->amount)->toBe(80_000);
    expect($extension->description)->not->toContain('inherited');
    expect((int) $booking->manual_discount_amount)->toBe(20_000);
});

it('does nothing extra when the original booking had no discount', function (): void {
    // Vanilla booking — no discount at all.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'total_amount' => 80_000, 'room_total' => 80_000,
        'balance_due' => 0,
        'manual_discount_percent' => 0.0,
        'manual_discount_amount' => 0,
    ]);

    $booking = app(ExtendBooking::class)->handle(
        $booking,
        Carbon::today()->addDay(),
        $this->operator,
    );

    $extension = BookingCharge::query()
        ->where('booking_id', $booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->first();

    expect((int) $extension->amount)->toBe(80_000);
    expect($extension->description)->not->toContain('inherited');
    expect((int) $booking->manual_discount_amount)->toBe(0);
});
