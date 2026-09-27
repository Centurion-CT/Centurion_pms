<?php

declare(strict_types=1);

use App\Enums\DepositType;
use App\Enums\RoomBookingType;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\Tenant;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

function makeRoom(array $overrides = []): Room
{
    /** @var Tenant $tenant */
    $tenant = Tenant::current();
    /** @var Property $property */
    $property = $tenant->property ?? Property::factory()->for($tenant)->create();

    return Room::factory()->for($tenant)->for($property)->create(array_merge([
        'base_rate' => 30_000,
        'weekly_discount_percent' => 0,
        'monthly_discount_percent' => 0,
        'deposit_type' => DepositType::None,
        'deposit_value' => 0,
        'booking_type' => RoomBookingType::ShortLet,
        'monthly_rate' => null,
        'min_nights' => 1,
    ], $overrides));
}

/* --------------------------------------------------------------------------
 *  Nightly subtotal
 * ------------------------------------------------------------------------ */

it('computes base_rate × nights when there are no overrides', function (): void {
    $room = makeRoom(['base_rate' => 30_000]);

    $b = app(PricingCalculator::class)->calculate(
        $room,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-04'),
    );

    expect($b->nights)->toBe(3);
    expect($b->subtotal)->toBe(90_000);
    expect($b->discountPercent)->toBe(0);
    expect($b->netSubtotal)->toBe(90_000);
    expect($b->nightlyRates)->toHaveCount(3);
    expect($b->nightlyRates[0]->source)->toBe('base');
});

it('throws when checkout <= checkin', function (): void {
    $room = makeRoom();

    expect(fn () => app(PricingCalculator::class)->calculate(
        $room,
        Carbon::parse('2026-06-05'),
        Carbon::parse('2026-06-05'),
    ))->toThrow(InvalidArgumentException::class);
});

/* --------------------------------------------------------------------------
 *  Seasonal overrides
 * ------------------------------------------------------------------------ */

it('uses override rate when one fully covers the requested range', function (): void {
    $room = makeRoom(['base_rate' => 30_000]);
    RoomPrice::factory()->for($room)->for($this->tenant)->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'rate' => 50_000,
        'label' => 'Peak Month',
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room,
        Carbon::parse('2026-06-10'),
        Carbon::parse('2026-06-13'),
    );

    expect($b->subtotal)->toBe(150_000); // 3 × 50,000
    foreach ($b->nightlyRates as $n) {
        expect($n->source)->toBe('override');
        expect($n->rate)->toBe(50_000);
        expect($n->overrideLabel)->toBe('Peak Month');
    }
});

it('mixes base + override per night when override partially covers the range', function (): void {
    $room = makeRoom(['base_rate' => 30_000]);
    // Override covers only 2 nights (the 5th + 6th) of a 4-night stay (3,4,5,6).
    RoomPrice::factory()->for($room)->for($this->tenant)->create([
        'start_date' => '2026-06-05',
        'end_date' => '2026-06-06',
        'rate' => 50_000,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room,
        Carbon::parse('2026-06-03'),
        Carbon::parse('2026-06-07'), // nights of 3,4,5,6
    );

    expect($b->subtotal)->toBe(2 * 30_000 + 2 * 50_000); // 160,000
    $sources = array_map(fn ($n) => $n->source, $b->nightlyRates);
    expect($sources)->toBe(['base', 'base', 'override', 'override']);
});

it('resolves multiple overlapping overrides to the most-recent (highest id)', function (): void {
    $room = makeRoom();
    RoomPrice::factory()->for($room)->for($this->tenant)->create([
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'rate' => 40_000,
    ]);
    RoomPrice::factory()->for($room)->for($this->tenant)->create([
        'start_date' => '2026-06-10', 'end_date' => '2026-06-12', 'rate' => 75_000, // newer wins
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-10'), Carbon::parse('2026-06-13'),
    );

    expect($b->subtotal)->toBe(3 * 75_000);
});

/* --------------------------------------------------------------------------
 *  Auto discounts
 * ------------------------------------------------------------------------ */

it('applies weekly_discount_percent at exactly 7 nights', function (): void {
    $room = makeRoom([
        'base_rate' => 20_000,
        'weekly_discount_percent' => 10,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-08'),
    );

    expect($b->nights)->toBe(7);
    expect($b->subtotal)->toBe(140_000);
    expect($b->discountPercent)->toBe(10);
    expect($b->discountAmount)->toBe(14_000);
    expect($b->netSubtotal)->toBe(126_000);
});

it('does NOT apply weekly discount at 6 nights', function (): void {
    $room = makeRoom([
        'base_rate' => 20_000,
        'weekly_discount_percent' => 10,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07'),
    );

    expect($b->discountPercent)->toBe(0);
    expect($b->netSubtotal)->toBe(120_000);
});

it('applies monthly_discount_percent at 28+ nights (instead of weekly)', function (): void {
    $room = makeRoom([
        'base_rate' => 10_000,
        'weekly_discount_percent' => 10,
        'monthly_discount_percent' => 25,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-29'),
    );

    expect($b->nights)->toBe(28);
    expect($b->discountPercent)->toBe(25);  // monthly wins
    expect($b->subtotal)->toBe(280_000);
    expect($b->discountAmount)->toBe(70_000);
    expect($b->netSubtotal)->toBe(210_000);
});

/* --------------------------------------------------------------------------
 *  Long-stay pricing
 * ------------------------------------------------------------------------ */

it('uses monthly_rate for explicit long-stay rooms at 28+ nights', function (): void {
    $room = makeRoom([
        'booking_type' => RoomBookingType::LongStay,
        'monthly_rate' => 600_000,
        'min_nights' => 28,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-29'), // 28 nights
    );

    expect($b->longStayPricing)->toBeTrue();
    expect($b->subtotal)->toBe(600_000); // 1 month
    expect($b->discountPercent)->toBe(0); // not stacked
});

it('charges 2 months for a 31-night long-stay booking', function (): void {
    $room = makeRoom([
        'booking_type' => RoomBookingType::LongStay,
        'monthly_rate' => 600_000,
        'min_nights' => 28,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-07-02'), // 31 nights
    );

    expect($b->longStayPricing)->toBeTrue();
    expect($b->subtotal)->toBe(1_200_000); // ceil(31/30) = 2 months
});

it('does NOT enter long-stay mode for short_let rooms even at 28+ nights', function (): void {
    $room = makeRoom([
        'base_rate' => 25_000,
        'monthly_rate' => 600_000,
        'booking_type' => RoomBookingType::ShortLet,   // not long-stay capable
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-29'),
    );

    expect($b->longStayPricing)->toBeFalse();
    expect($b->subtotal)->toBe(28 * 25_000);
});

/* --------------------------------------------------------------------------
 *  Fees
 * ------------------------------------------------------------------------ */

it('charges Paystack fee = 1.5% × net + ₦100, uncapped on small bookings', function (): void {
    $room = makeRoom(['base_rate' => 10_000]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'),
    );

    expect($b->subtotal)->toBe(10_000);
    expect($b->paystackFee)->toBe(250);   // 1.5% × 10,000 = 150 + 100
});

it('caps Paystack fee at ₦2,000 on large bookings', function (): void {
    $room = makeRoom(['base_rate' => 1_000_000]); // one night, 1M Naira

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'),
    );

    expect($b->paystackFee)->toBe(2_000); // would be 15,100 uncapped
});

it('charges Keverna fee = 0.5% × net, capped at ₦2,000', function (): void {
    $room = makeRoom(['base_rate' => 100_000]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'),
    );

    expect($b->kevernaFee)->toBe(500);  // 0.5% × 100,000

    $expensive = makeRoom(['base_rate' => 1_000_000]);
    $b2 = app(PricingCalculator::class)->calculate(
        $expensive, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'),
    );
    expect($b2->kevernaFee)->toBe(2_000); // capped
});

it('adds both fees to totalDue but pays operator the net subtotal in full (PRD §4.1)', function (): void {
    $room = makeRoom(['base_rate' => 50_000]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-03'), // 2 nights
    );

    expect($b->netSubtotal)->toBe(100_000);
    expect($b->paystackFee)->toBe(1_600); // 1.5% × 100,000 = 1,500 + 100
    expect($b->kevernaFee)->toBe(500);
    expect($b->totalDue)->toBe(102_100);
    expect($b->operatorPayout)->toBe(100_000); // operator gets net in full
});

/* --------------------------------------------------------------------------
 *  Deposits
 * ------------------------------------------------------------------------ */

it('computes a fixed deposit', function (): void {
    $room = makeRoom([
        'base_rate' => 50_000,
        'deposit_type' => DepositType::Fixed,
        'deposit_value' => 15_000,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-03'),
    );

    expect($b->depositAmount)->toBe(15_000);
});

it('computes a percent deposit (PRD §6.4.3 — 30% default)', function (): void {
    $room = makeRoom([
        'base_rate' => 50_000,
        'deposit_type' => DepositType::Percent,
        'deposit_value' => 30,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-03'),
    );

    expect($b->netSubtotal)->toBe(100_000);
    expect($b->depositAmount)->toBe(30_000);
});

it('returns 0 deposit when deposit_type=None', function (): void {
    $room = makeRoom([
        'deposit_type' => DepositType::None,
        'deposit_value' => 0,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'),
    );

    expect($b->depositAmount)->toBe(0);
});

it('deposit % is computed against the discounted netSubtotal, not the gross', function (): void {
    $room = makeRoom([
        'base_rate' => 20_000,
        'weekly_discount_percent' => 10,
        'deposit_type' => DepositType::Percent,
        'deposit_value' => 30,
    ]);

    $b = app(PricingCalculator::class)->calculate(
        $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-08'), // 7 nights
    );

    // subtotal 140,000 - 10% = 126,000 net; 30% of net = 37,800
    expect($b->netSubtotal)->toBe(126_000);
    expect($b->depositAmount)->toBe(37_800);
});
