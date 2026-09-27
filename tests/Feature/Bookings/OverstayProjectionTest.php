<?php

declare(strict_types=1);

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'overstay-proj']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 30_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->cat, 'category')->create([
        'room_number' => '101',
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── Booking model methods ────────────────────────────────────────── */

it('projected overstay nights is 0 when the guest is on-time', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(1),
        'checkout_date' => Carbon::today()->addDays(2),
    ]);

    expect($booking->projectedOverstayNights())->toBe(0);
    expect($booking->projectedOverstayAmount())->toBe(0);
});

it('projected overstay counts nights past the booked checkout', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(5),
        'checkout_date' => Carbon::today()->subDays(2),  // 2 nights over
    ]);

    expect($booking->projectedOverstayNights())->toBe(2);
    expect($booking->projectedOverstayAmount())->toBe(60_000);  // 2 × ₦30k
});

it('projected overstay is 0 once the full overstay is on the ledger', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(5),
        'checkout_date' => Carbon::today()->subDays(2),
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Overstay,
        'description' => '2 overstay nights',
        'amount' => 60_000,  // 2 × ₦30k — full coverage
        'settled' => false,
    ]);

    expect($booking->fresh()->projectedOverstayAmount())->toBe(0);
});

it('projected overstay returns the still-uncharged remainder when partially billed', function (): void {
    // Regression: this used to return 0 the moment ANY overstay
    // charge existed, which under-reported when the nightly cron
    // missed days. Guest is 5 nights over, only 1 night on the
    // ledger → 4 nights × ₦30k = ₦120k still to bill.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(10),
        'checkout_date' => Carbon::today()->subDays(5),
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Overstay,
        'description' => '1 night overstay (auto)',
        'amount' => 30_000,
        'settled' => true,
    ]);

    $fresh = $booking->fresh();
    expect($fresh->projectedOverstayNights())->toBe(5);
    expect($fresh->overstayNightsCharged())->toBe(1);
    expect($fresh->unchargedOverstayNights())->toBe(4);
    expect($fresh->projectedOverstayAmount())->toBe(120_000);
});

it('non-CheckedIn statuses have zero projected overstay even when past checkout', function (): void {
    $checkedOut = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'checkin_date' => Carbon::today()->subDays(5),
        'checkout_date' => Carbon::today()->subDays(2),
    ]);
    $noShow = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::NoShow,
        'checkin_date' => Carbon::today()->subDays(5),
        'checkout_date' => Carbon::today()->subDays(2),
    ]);

    expect($checkedOut->projectedOverstayAmount())->toBe(0);
    expect($noShow->projectedOverstayAmount())->toBe(0);
});

it('outstandingTotal folds in the projected uncharged overstay', function (): void {
    // Semantic shift: outstandingTotal now includes accruing overstay
    // that hasn't been billed yet, so every surface (dashboard,
    // reservation-show tile, check-out guard) reflects the true
    // amount a guest owes without callers having to add
    // projectedOverstayAmount() on top. totalOwed() is now an alias.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(5),
        'checkout_date' => Carbon::today()->subDays(1),  // 1 night over
        'balance_due' => 10_000,
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::FoodBeverage,
        'description' => 'Room service',
        'amount' => 5_000,
        'settled' => false,
    ]);

    $fresh = $booking->fresh();
    // balance_due (10k) + unsettled charges (5k) + projected overstay (30k) = 45k
    expect($fresh->outstandingTotal())->toBe(45_000);
    expect($fresh->projectedOverstayAmount())->toBe(30_000);
    expect($fresh->totalOwed())->toBe(45_000);
});

/* ── DashboardSnapshot integration ────────────────────────────────── */

it('DashboardSnapshot includes projected overstay in the outstanding KPI', function (): void {
    // Overstaying guest with no charges — pure overstay projection.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(4),
        'checkout_date' => Carbon::today()->subDays(1),
        'balance_due' => 0,
    ]);

    $snapshot = app(DashboardSnapshot::class);
    $data = $snapshot->build(bypass: true);

    expect($data['kpis']['outstanding']['value'])->toBe(30_000);
});

it('DashboardSnapshot lists overstaying guests in the outstanding widget', function (): void {
    $overstayer = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(4),
        'checkout_date' => Carbon::today()->subDays(1),
        'balance_due' => 0,      // no traditional outstanding — just overstay
        'guest_name' => 'Overstaying Ada',
    ]);

    $data = app(DashboardSnapshot::class)->build(bypass: true);

    expect($data['outstanding']->pluck('id')->all())->toContain($overstayer->id);
});

it('DashboardSnapshot excludes overstayers who already have an Overstay charge', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(4),
        'checkout_date' => Carbon::today()->subDays(1),
        'balance_due' => 0,
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Overstay,
        'description' => 'Overstay',
        'amount' => 30_000,
        'settled' => false,
    ]);

    $data = app(DashboardSnapshot::class)->build(bypass: true);

    // The Overstay charge itself makes the booking outstanding via the
    // unsettled-charge path, so KPI still includes 30k — but ONCE, not
    // twice. Confirm no double-counting.
    expect($data['kpis']['outstanding']['value'])->toBe(30_000);
});

/* ── FinanceSnapshot integration ──────────────────────────────────── */

it('FinanceSnapshot includes projected overstay in the outstanding KPI', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(4),
        'checkout_date' => Carbon::today()->subDays(1),
        'balance_due' => 0,
    ]);

    $kpis = app(FinanceSnapshot::class)->kpis(
        Carbon::today()->subDays(30),
        Carbon::today(),
    );

    expect($kpis['outstanding'])->toBe(30_000);
});
