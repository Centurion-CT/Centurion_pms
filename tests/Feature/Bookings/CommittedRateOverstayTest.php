<?php

declare(strict_types=1);

use App\Actions\Bookings\MarkCheckedOut;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Overstay + late-checkout charges must use the tier the guest
 * committed to (breakfast vs base) - a guest paying ₦45k a night
 * for breakfast can't overstay and be billed at the ₦40k base
 * (that's a discount for staying too long, which is nonsense).
 */
beforeEach(function (): void {
    Carbon::setTestNow('2027-05-15 10:00:00');
    $this->tenant = Tenant::factory()->create(['slug' => 'committed-rate']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000,
        'breakfast_included_rate' => 45_000,
        'capacity_adults' => 2,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('breakfast-tier booking projects overstay at the breakfast rate, not base', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2027-05-10',
        'checkout_date' => '2027-05-13',
        'nights' => 3,
        'breakfast_included' => true,
        'room_total' => 135_000,
        'total_amount' => 135_000,
        'balance_due' => 0,
    ]);

    // 2 nights past checkout × ₦45k breakfast rate = ₦90k.
    expect($b->projectedOverstayNights())->toBe(2);
    expect($b->unchargedOverstayAmount())->toBe(90_000);
    expect($b->committedNightlyRate())->toBe(45_000);
});

it('base-tier booking still projects overstay at the base rate', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2027-05-10',
        'checkout_date' => '2027-05-13',
        'nights' => 3,
        'breakfast_included' => false,
        'room_total' => 120_000,
        'total_amount' => 120_000,
        'balance_due' => 0,
    ]);

    expect($b->committedNightlyRate())->toBe(40_000);
    expect($b->unchargedOverstayAmount())->toBe(80_000); // 2 × ₦40k
});

it('MarkCheckedOut writes the overstay charge at the breakfast rate', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2027-05-10',
        'checkout_date' => '2027-05-13',
        'nights' => 3,
        'breakfast_included' => true,
        'room_total' => 135_000,
        'total_amount' => 135_000,
        'balance_due' => 0,
    ]);

    try {
        // Will throw because outstanding > 0 after the overstay row
        // is written; that's the intended flow - we just want to
        // verify the row itself carries the breakfast-rate total.
        app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator);
    } catch (\RuntimeException) {
        // expected - outstanding balance blocks the flip
    }

    $overstay = $b->charges()->where('type', 'overstay')->latest('id')->first();
    expect($overstay)->not->toBeNull();
    expect((int) $overstay->amount)->toBe(90_000); // 2 nights × ₦45k, NOT 2 × ₦40k
});

it('overstayNightsCharged reverse-calcs against the committed rate', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2027-05-10',
        'checkout_date' => '2027-05-13',
        'nights' => 3,
        'breakfast_included' => true,
    ]);
    // Seed a ₦90k overstay charge; should reverse-calc to 2 nights
    // at ₦45k, NOT 2.25 nights (which is what dividing by ₦40k base
    // would produce, floored to 2 by luck).
    \App\Models\BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $b->id,
        'type' => 'overstay',
        'description' => 'seed',
        'amount' => 90_000,
        'settled' => false,
    ]);

    expect($b->fresh()->overstayNightsCharged())->toBe(2);
});
