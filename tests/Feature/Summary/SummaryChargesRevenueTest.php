<?php

declare(strict_types=1);

use App\Actions\Bookings\AddBookingCharge;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Summary\Index as SummaryIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Regression: Summary "Total revenue" was under-counting because it
 * only summed bookings.total_amount and ignored booking_charges
 * (F&B, extension, laundry, etc.). Guests paying for those extras
 * inflated the Payments-page till, which made the two pages
 * disagree by hundreds of thousands of ₦.
 *
 * FinanceSnapshot::kpis now exposes `chargesBilled` (SUM of
 * BookingCharge.amount with created_at inside the window), and
 * Summary + the weekly PDF fold that into the Total revenue.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'summary-charges']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 30_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('FinanceSnapshot::kpis exposes chargesBilled scoped to the window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-10',
        'total_amount' => 60_000,
    ]);
    // Two extras added this month.
    app(AddBookingCharge::class)->handle($booking, $this->manager,
        BookingChargeType::FoodBeverage, 'dinner', 15_000);
    app(AddBookingCharge::class)->handle($booking, $this->manager,
        BookingChargeType::RoomService, 'drinks', 5_000);

    // One charge from last month — outside the current window, should be excluded.
    $oldCharge = app(AddBookingCharge::class)->handle($booking, $this->manager,
        BookingChargeType::FoodBeverage, 'old', 999_999);
    $oldCharge->forceFill(['created_at' => Carbon::parse('2030-05-01'), 'updated_at' => Carbon::parse('2030-05-01')])->save();

    $kpis = app(FinanceSnapshot::class)->kpis(
        Carbon::parse('2030-06-01'), Carbon::parse('2030-06-30'),
    );

    expect($kpis['chargesBilled'])->toBe(20_000);
});

it('Summary Total revenue reflects cash collected in the window (matches Payments)', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-10',
        'total_amount' => 60_000,
    ]);
    // A cash payment collected this month.
    \App\Models\Payment::factory()->for($this->tenant)->succeeded()->create([
        'booking_id' => $booking->id,
        'amount' => 45_000,
        'paid_at' => Carbon::parse('2030-06-11 12:00:00'),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->set('period', 'this_month')
        // Total revenue is cash-in (₦45,000), not the booked total —
        // matches the Payments page's Net payout number.
        ->assertSeeHtml('&#8358;45,000');
    Carbon::setTestNow();
});

it('Summary revenue breakdown lumps room + extras into "Front office (rooms)" (cash basis)', function (): void {
    // Under cash-basis we don't split the front-office bar into
    // rooms vs extras — a booking payment might cover either and
    // there's no clean way to attribute without more schema. The
    // extras revenue is already inside the booking-cash total, so
    // the breakdown row shows the combined figure.
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-10',
        'total_amount' => 60_000,
    ]);
    app(AddBookingCharge::class)->handle($booking, $this->manager,
        BookingChargeType::FoodBeverage, 'dinner', 15_000);

    Livewire::actingAs($this->manager, 'web')
        ->test(SummaryIndex::class)
        ->set('period', 'this_month')
        ->assertSee('Front office (rooms)')
        ->assertDontSee('Front office (extras)');
});
