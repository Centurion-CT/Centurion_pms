<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\BookingSource;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;

/**
 * Optional per-category "with complimentary breakfast" rate tier.
 * When set, guests + operators can pick either the base rate or the
 * breakfast rate at booking time; picked booking rides through
 * CreateBookingRequest → PricingCalculator → Booking.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'breakfast-tier']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();

    // Base ₦50k, breakfast ₦55k → ₦5k delta per night for the tier.
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 50_000,
        'breakfast_included_rate' => 55_000,
        'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ─── Model + calculator ────────────────────────────────────────── */

it('category exposes hasBreakfastOption + effectiveNightlyRate helpers', function (): void {
    expect($this->category->hasBreakfastOption())->toBeTrue();
    expect($this->category->effectiveNightlyRate(false))->toBe(50_000);
    expect($this->category->effectiveNightlyRate(true))->toBe(55_000);

    $noBreakfast = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 40_000, 'breakfast_included_rate' => null,
    ]);
    expect($noBreakfast->hasBreakfastOption())->toBeFalse();
    expect($noBreakfast->effectiveNightlyRate(true))->toBe(40_000); // falls back
});

it('PricingCalculator swaps to the breakfast rate when the flag is set', function (): void {
    $checkin  = Carbon::today()->addDay();
    $checkout = $checkin->copy()->addDays(2);   // 2 nights

    $noBreakfast = app(PricingCalculator::class)
        ->calculate($this->room, $checkin, $checkout, 0, false);
    $withBreakfast = app(PricingCalculator::class)
        ->calculate($this->room, $checkin, $checkout, 0, true);

    expect($noBreakfast->subtotal)->toBe(100_000);   // 2 × 50k
    expect($withBreakfast->subtotal)->toBe(110_000); // 2 × 55k
});

it('PricingCalculator ignores the flag when the category has no breakfast rate', function (): void {
    $bare = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 40_000, 'breakfast_included_rate' => null,
    ]);
    $bareRoom = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $bare->id, 'status' => RoomStatus::Active->value,
    ]);

    $preview = app(PricingCalculator::class)
        ->calculate($bareRoom, Carbon::today()->addDay(), Carbon::today()->addDays(2), 0, true);

    expect($preview->subtotal)->toBe(40_000);   // still base
});

/* ─── CreateBooking end-to-end ──────────────────────────────────── */

it('records breakfast_included = true and charges the breakfast tier when picked', function (): void {
    $req = new CreateBookingRequest(
        guestName: 'Chi Guest', guestEmail: 'chi@x.test', guestPhone: '08012345678',
        adults: 1, children: 0,
        checkin:  Carbon::today()->addDay(),
        checkout: Carbon::today()->addDays(2),
        paymentType: PaymentType::Full,
        source: BookingSource::WalkIn,
        breakfastIncluded: true,
    );
    $booking = app(CreateBooking::class)->handle($this->room, $req);

    expect($booking->breakfast_included)->toBeTrue();
    expect((int) $booking->room_total)->toBe(55_000);   // 1 night × breakfast rate
    expect((int) $booking->total_amount)->toBe(55_000); // walk-in → no fees
});

it('records breakfast_included = false and charges the base rate when declined', function (): void {
    $req = new CreateBookingRequest(
        guestName: 'Chi Guest', guestEmail: 'chi@x.test', guestPhone: '08012345678',
        adults: 1, children: 0,
        checkin:  Carbon::today()->addDay(),
        checkout: Carbon::today()->addDays(2),
        paymentType: PaymentType::Full,
        source: BookingSource::WalkIn,
        breakfastIncluded: false,
    );
    $booking = app(CreateBooking::class)->handle($this->room, $req);

    expect($booking->breakfast_included)->toBeFalse();
    expect((int) $booking->room_total)->toBe(50_000);
});

it('silently ignores the flag when the room category has no breakfast rate', function (): void {
    $bare = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 40_000, 'breakfast_included_rate' => null,
    ]);
    $bareRoom = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $bare->id, 'status' => RoomStatus::Active->value,
    ]);

    $req = new CreateBookingRequest(
        guestName: 'Chi Guest', guestEmail: 'chi@x.test', guestPhone: '08012345678',
        adults: 1, children: 0,
        checkin:  Carbon::today()->addDay(),
        checkout: Carbon::today()->addDays(2),
        paymentType: PaymentType::Full,
        source: BookingSource::WalkIn,
        breakfastIncluded: true,   // guest asked for it, but category doesn't offer
    );
    $booking = app(CreateBooking::class)->handle($bareRoom, $req);

    // The persisted breakfast_included flag reflects the ACTUAL charged
    // tier — false because the category couldn't honour the request.
    expect($booking->breakfast_included)->toBeFalse();
    expect((int) $booking->room_total)->toBe(40_000);
});
