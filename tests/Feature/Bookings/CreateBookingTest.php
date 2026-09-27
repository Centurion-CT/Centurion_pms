<?php

declare(strict_types=1);

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\DepositType;
use App\Enums\PaymentStatus;
use App\Enums\BookingSource;
use App\Enums\ManualDiscountType;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Availability\RangeNotAvailableException;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000,
        'capacity_adults' => 2,
        'capacity_children' => 1,
        'min_nights' => 1,
    ]);
    $this->room = Room::factory()
        ->for($this->tenant)
        ->for($this->property)
        ->for($this->category, 'category')
        ->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

function bookingReq(array $overrides = []): CreateBookingRequest
{
    return new CreateBookingRequest(
        guestName: $overrides['name'] ?? 'Test Guest',
        guestEmail: $overrides['email'] ?? 'guest@x.test',
        guestPhone: $overrides['phone'] ?? '+2348012345678',
        adults: $overrides['adults'] ?? 2,
        children: $overrides['children'] ?? 0,
        checkin: $overrides['checkin'] ?? Carbon::tomorrow(),
        checkout: $overrides['checkout'] ?? Carbon::tomorrow()->addDays(3),
        paymentType: $overrides['payment'] ?? PaymentType::Full,
        specialRequests: $overrides['notes'] ?? null,
    );
}

/* ---------- Happy path ---------- */

it('creates a Pending booking with priced totals + reserves the nights', function (): void {
    $booking = app(CreateBooking::class)->handle(
        $this->room,
        bookingReq([
            'checkin' => Carbon::parse('2030-07-01'),
            'checkout' => Carbon::parse('2030-07-04'),
        ]),
    );

    expect($booking)->toBeInstanceOf(Booking::class);
    expect($booking->booking_status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::Pending);
    expect($booking->nights)->toBe(3);
    expect($booking->room_total)->toBe(90_000);
    expect($booking->paystack_fee)->toBeGreaterThan(0);
    expect($booking->keverna_fee)->toBeGreaterThan(0);
    expect($booking->total_amount)
        ->toBe($booking->room_total + $booking->paystack_fee + $booking->keverna_fee);

    // Three Booked rows in availability_blocks.
    $blocks = AvailabilityBlock::where('room_id', $this->room->id)->get();
    expect($blocks)->toHaveCount(3);
    expect($blocks->pluck('type')->unique()->all())->toBe([AvailabilityBlockType::Booked]);
    expect($blocks->pluck('booking_id')->unique()->all())->toBe([$booking->id]);
});

it('generates a unique KV- prefixed booking ref', function (): void {
    $booking = app(CreateBooking::class)->handle($this->room, bookingReq());

    expect($booking->booking_ref)->toStartWith('KV-');
    expect($booking->booking_ref)->toMatch('/^KV-[A-Z2-9]{8}$/');
});

it('produces distinct refs across many bookings (collision-free)', function (): void {
    $refs = [];
    for ($i = 1; $i <= 9; $i++) {
        $day = sprintf('2030-09-%02d', $i);
        $b = app(CreateBooking::class)->handle(
            $this->room,
            bookingReq([
                'checkin' => Carbon::parse($day),
                'checkout' => Carbon::parse($day)->addDay(),
            ]),
        );
        $refs[] = $b->booking_ref;
    }
    expect(array_unique($refs))->toHaveCount(9);
});

it('records full payment fields when paymentType=Full', function (): void {
    $b = app(CreateBooking::class)->handle(
        $this->room, bookingReq(['payment' => PaymentType::Full]),
    );

    expect($b->deposit_amount)->toBe(0);
    expect($b->balance_due)->toBe(0);
});

/* ---------- Manual front-desk discount ---------- */

it('applies a manual percent discount and records the operator', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create(['name' => 'Ada Frontdesk']);

    $req = new CreateBookingRequest(
        guestName: 'Discount Guest',
        guestEmail: 'discount@x.test',
        guestPhone: '+2348011112222',
        adults: 1,
        children: 0,
        checkin: Carbon::parse('2030-08-01'),
        checkout: Carbon::parse('2030-08-03'), // 2 nights × ₦30k = ₦60k
        paymentType: PaymentType::Full,
        source: BookingSource::WalkIn,
        autoApplyDiscounts: false,
        manualDiscountType: ManualDiscountType::Percent,
        manualDiscountPercent: 25.0,
        manualDiscountByOperatorId: $operator->id,
    );

    $b = app(CreateBooking::class)->handle($this->room, $req);

    // ₦60k × 25% = ₦15k off, ₦45k left.
    expect($b->manual_discount_type)->toBe(ManualDiscountType::Percent);
    expect((float) $b->manual_discount_percent)->toBe(25.00);
    expect($b->manual_discount_amount)->toBe(15_000);
    expect($b->manual_discount_by_operator_id)->toBe($operator->id);
    expect($b->total_amount)->toBe(45_000);
    expect($b->manualDiscountBy?->name)->toBe('Ada Frontdesk');
});

it('applies a fractional percent discount like 7.5', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create();

    $req = new CreateBookingRequest(
        guestName: 'Guest', guestEmail: 'g@x.test', guestPhone: '+2348012221111',
        adults: 1, children: 0,
        checkin: Carbon::parse('2030-08-04'),
        checkout: Carbon::parse('2030-08-08'), // 4 nights × ₦30k = ₦120k
        paymentType: PaymentType::Full,
        source: BookingSource::WalkIn,
        autoApplyDiscounts: false,
        manualDiscountType: ManualDiscountType::Percent,
        manualDiscountPercent: 7.5,
        manualDiscountByOperatorId: $operator->id,
    );

    $b = app(CreateBooking::class)->handle($this->room, $req);

    // ₦120k × 7.5% = ₦9,000 off, ₦111,000 left.
    expect((float) $b->manual_discount_percent)->toBe(7.50);
    expect($b->manual_discount_amount)->toBe(9_000);
    expect($b->total_amount)->toBe(111_000);
});

it('applies a fixed ₦ discount verbatim and derives the percent for reports', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create();

    $req = new CreateBookingRequest(
        guestName: 'Guest', guestEmail: 'g@x.test', guestPhone: '+2348011113333',
        adults: 1, children: 0,
        checkin: Carbon::parse('2030-08-10'),
        checkout: Carbon::parse('2030-08-12'), // 2 nights × ₦30k = ₦60k
        paymentType: PaymentType::Full,
        source: BookingSource::WalkIn,
        autoApplyDiscounts: false,
        manualDiscountType: ManualDiscountType::Fixed,
        manualDiscountAmount: 5_000,
        manualDiscountByOperatorId: $operator->id,
    );

    $b = app(CreateBooking::class)->handle($this->room, $req);

    // Flat ₦5k off — no percent conversion at input, but derived
    // percent (5k/60k ≈ 8.33) helps reports lump both modes together.
    expect($b->manual_discount_type)->toBe(ManualDiscountType::Fixed);
    expect($b->manual_discount_amount)->toBe(5_000);
    expect((float) $b->manual_discount_percent)->toBe(8.33);
    expect($b->total_amount)->toBe(55_000);
});

it('caps a fixed ₦ discount at the total (never lets total go negative)', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create();

    $req = new CreateBookingRequest(
        guestName: 'Guest', guestEmail: 'g@x.test', guestPhone: '+2348011114444',
        adults: 1, children: 0,
        checkin: Carbon::parse('2030-08-13'),
        checkout: Carbon::parse('2030-08-14'), // 1 night × ₦30k
        paymentType: PaymentType::Full,
        source: BookingSource::WalkIn,
        autoApplyDiscounts: false,
        manualDiscountType: ManualDiscountType::Fixed,
        manualDiscountAmount: 999_999,  // way over total
        manualDiscountByOperatorId: $operator->id,
    );

    $b = app(CreateBooking::class)->handle($this->room, $req);

    expect($b->manual_discount_amount)->toBe(30_000);
    expect($b->total_amount)->toBe(0);
});

it('leaves discount fields at zero when the operator does not apply one', function (): void {
    $b = app(CreateBooking::class)->handle($this->room, bookingReq());

    expect((float) $b->manual_discount_percent)->toBe(0.00);
    expect($b->manual_discount_amount)->toBe(0);
    expect($b->manual_discount_by_operator_id)->toBeNull();
});

it('clamps manual discount to 0-100 and floors the ₦ off', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create();

    $req = new CreateBookingRequest(
        guestName: 'Guest', guestEmail: 'g@x.test', guestPhone: '+2348012221111',
        adults: 1, children: 0,
        checkin: Carbon::parse('2030-08-10'),
        checkout: Carbon::parse('2030-08-11'), // 1 night ₦30k
        paymentType: PaymentType::Full,
        source: BookingSource::WalkIn,
        autoApplyDiscounts: false,
        manualDiscountPercent: 250.0,  // clamped to 100
        manualDiscountByOperatorId: $operator->id,
    );

    $b = app(CreateBooking::class)->handle($this->room, $req);

    expect((float) $b->manual_discount_percent)->toBe(100.00);
    expect($b->manual_discount_amount)->toBe(30_000);
    expect($b->total_amount)->toBe(0);
});

it('records deposit + balance fields when paymentType=Deposit', function (): void {
    $this->room->forceFill([
        'deposit_type' => DepositType::Percent,
        'deposit_value' => 30,
    ])->save();

    $b = app(CreateBooking::class)->handle(
        $this->room, bookingReq(['payment' => PaymentType::Deposit]),
    );

    expect($b->deposit_amount)->toBeGreaterThan(0);
    expect($b->balance_due)->toBe($b->total_amount - $b->deposit_amount);
});

/* ---------- Validation ---------- */

it('rejects an inactive room', function (): void {
    $this->room->forceFill(['status' => RoomStatus::Inactive])->save();

    expect(fn () => app(CreateBooking::class)->handle($this->room->refresh(), bookingReq()))
        ->toThrow(BookingValidationException::class);
});

it('rejects past checkin dates', function (): void {
    expect(fn () => app(CreateBooking::class)->handle(
        $this->room,
        bookingReq(['checkin' => Carbon::yesterday(), 'checkout' => Carbon::today()]),
    ))->toThrow(BookingValidationException::class);
});

it('rejects checkout <= checkin', function (): void {
    $d = Carbon::tomorrow();
    expect(fn () => app(CreateBooking::class)->handle(
        $this->room, bookingReq(['checkin' => $d, 'checkout' => $d]),
    ))->toThrow(BookingValidationException::class);
});

it('rejects a stay shorter than the room min_nights', function (): void {
    $this->room->forceFill(['min_nights' => 3])->save();

    expect(fn () => app(CreateBooking::class)->handle(
        $this->room->refresh(),
        bookingReq([
            'checkin' => Carbon::tomorrow(),
            'checkout' => Carbon::tomorrow()->addDays(2),
        ]),
    ))->toThrow(BookingValidationException::class);
});

it('rejects adults < 1', function (): void {
    expect(fn () => app(CreateBooking::class)->handle(
        $this->room, bookingReq(['adults' => 0]),
    ))->toThrow(BookingValidationException::class);
});

it('rejects exceeding adult capacity', function (): void {
    expect(fn () => app(CreateBooking::class)->handle(
        $this->room, bookingReq(['adults' => 99]),
    ))->toThrow(BookingValidationException::class);
});

it('rejects exceeding child capacity', function (): void {
    expect(fn () => app(CreateBooking::class)->handle(
        $this->room, bookingReq(['children' => 99]),
    ))->toThrow(BookingValidationException::class);
});

/* ---------- Race condition (the §11 firewall) ---------- */

it('rejects a booking that overlaps an existing booked night', function (): void {
    // First booking holds 2030-07-01..03
    app(CreateBooking::class)->handle(
        $this->room, bookingReq([
            'checkin' => Carbon::parse('2030-07-01'),
            'checkout' => Carbon::parse('2030-07-04'),
        ]),
    );

    expect(fn () => app(CreateBooking::class)->handle(
        $this->room, bookingReq([
            'email' => 'second@x.test',
            'checkin' => Carbon::parse('2030-07-03'),
            'checkout' => Carbon::parse('2030-07-05'),
        ]),
    ))->toThrow(RangeNotAvailableException::class);
});

it('rolls back the Booking row when reserveRangeForBooking throws (atomicity)', function (): void {
    app(CreateBooking::class)->handle(
        $this->room, bookingReq([
            'checkin' => Carbon::parse('2030-07-01'),
            'checkout' => Carbon::parse('2030-07-04'),
        ]),
    );

    expect(Booking::count())->toBe(1);

    try {
        app(CreateBooking::class)->handle(
            $this->room, bookingReq([
                'email' => 'rollback@x.test',
                'checkin' => Carbon::parse('2030-07-03'),
                'checkout' => Carbon::parse('2030-07-05'),
            ]),
        );
    } catch (RangeNotAvailableException) {
        // expected
    }

    // No orphan Booking row from the racy second attempt.
    expect(Booking::count())->toBe(1);
});

it('rejects a booking on dates the operator has manually blocked', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->create([
        'date' => '2030-07-02', 'type' => AvailabilityBlockType::Blocked,
    ]);

    expect(fn () => app(CreateBooking::class)->handle(
        $this->room, bookingReq([
            'checkin' => Carbon::parse('2030-07-01'),
            'checkout' => Carbon::parse('2030-07-04'),
        ]),
    ))->toThrow(RangeNotAvailableException::class);
});

/* ---------- Half-open interval payoff ---------- */

it('allows back-to-back bookings on the same checkout/checkin date', function (): void {
    app(CreateBooking::class)->handle(
        $this->room, bookingReq([
            'checkin' => Carbon::parse('2030-07-01'),
            'checkout' => Carbon::parse('2030-07-05'),
        ]),
    );

    $second = app(CreateBooking::class)->handle(
        $this->room, bookingReq([
            'email' => 'second@x.test',
            'checkin' => Carbon::parse('2030-07-05'),
            'checkout' => Carbon::parse('2030-07-07'),
        ]),
    );

    expect($second->id)->toBeGreaterThan(0);
});

/* ---------- Pricing parity ---------- */

it('totals match what PricingCalculator returned (fees added on top, operator gets net)', function (): void {
    $b = app(CreateBooking::class)->handle($this->room, bookingReq([
        'checkin' => Carbon::parse('2030-08-01'),
        'checkout' => Carbon::parse('2030-08-04'), // 3 nights × 30,000 = 90,000
    ]));

    // Fee model post-marketplace launch: Paystack is 1.5% of
    // subtotal (no flat portion any more - waived for hospitality
    // sizes), capped at ₦2,000. Keverna is 5% of subtotal,
    // effectively uncapped (10M safety rail).
    $expectedPaystack = (int) round(90_000 * 0.015);   // 1,350
    $expectedKeverna  = (int) round(90_000 * 0.05);    // 4,500

    expect($b->room_total)->toBe(90_000);
    expect($b->paystack_fee)->toBe($expectedPaystack);
    expect($b->keverna_fee)->toBe($expectedKeverna);
    expect($b->total_amount)->toBe(90_000 + $expectedPaystack + $expectedKeverna);
});
