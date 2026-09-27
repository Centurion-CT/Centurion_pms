<?php

declare(strict_types=1);

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Regression suite for the "operator adds a mid-stay charge but the
 * platform-wide Outstanding cards don't move" bug. All three aggregations
 * (Dashboard KPI, Finance KPI, and the Dashboard outstanding-balances
 * widget) must reflect unsettled BookingCharge rows, not just
 * booking.balance_due.
 */

beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'outstanding-charges']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
    Carbon::setTestNow();
});

/**
 * Helper: attach an unsettled charge to a booking.
 */
function seedUnsettledCharge(Booking $booking, int $amount): BookingCharge
{
    return BookingCharge::query()->create([
        'tenant_id'   => $booking->tenant_id,
        'booking_id'  => $booking->id,
        'type'        => BookingChargeType::FoodBeverage,
        'description' => 'indomie and egg',
        'amount'      => $amount,
        'settled'     => false,
    ]);
}

/* ------------------------------------------------------------------ */
/* Finance snapshot                                                    */
/* ------------------------------------------------------------------ */

it('adds unsettled BookingCharges into Finance outstanding KPI', function (): void {
    // Checked-in booking fully paid up front (balance_due = 0).
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount'   => 25_600,
        'balance_due'    => 0,
    ]);
    // Operator adds a ₦15,000 F&B extra mid-stay.
    seedUnsettledCharge($booking, 15_000);

    $kpis = app(FinanceSnapshot::class)->kpis(
        Carbon::today()->subDays(7),
        Carbon::today(),
    );

    // The old (broken) code returned 0 here.
    expect($kpis['outstanding'])->toBe(15_000);
});

it('sums balance_due AND unsettled charges', function (): void {
    // Booking on hold with a balance still owed on the room…
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold,
        'total_amount'   => 50_000,
        'balance_due'    => 30_000,
    ]);
    // …plus a mid-stay extra.
    seedUnsettledCharge($booking, 5_000);

    $kpis = app(FinanceSnapshot::class)->kpis(
        Carbon::today()->subDays(7),
        Carbon::today(),
    );

    expect($kpis['outstanding'])->toBe(35_000);
});

it('ignores settled charges', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'balance_due'    => 0,
    ]);
    BookingCharge::query()->create([
        'tenant_id'   => $booking->tenant_id,
        'booking_id'  => $booking->id,
        'type'        => BookingChargeType::FoodBeverage,
        'description' => 'dinner',
        'amount'      => 8_000,
        'settled'     => true,
        'settled_at'  => now(),
    ]);

    $kpis = app(FinanceSnapshot::class)->kpis(
        Carbon::today()->subDays(7),
        Carbon::today(),
    );

    expect($kpis['outstanding'])->toBe(0);
});

/* ------------------------------------------------------------------ */
/* Dashboard snapshot                                                  */
/* ------------------------------------------------------------------ */

it('adds unsettled charges to the Dashboard outstanding KPI', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'balance_due'    => 0,
    ]);
    seedUnsettledCharge($booking, 15_000);

    $snapshot = app(DashboardSnapshot::class)->build(bypass: true);

    expect($snapshot['kpis']['outstanding']['value'])->toBe(15_000);
});

it('surfaces bookings with unsettled charges in the Dashboard outstanding-balances widget', function (): void {
    // Fully-paid on the room but has an unsettled F&B charge — the old
    // widget missed this because it filtered `balance_due > 0` only.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'balance_due'    => 0,
        'guest_name'     => 'Frank Mike',
    ]);
    seedUnsettledCharge($booking, 15_000);

    $snapshot = app(DashboardSnapshot::class)->build(bypass: true);

    expect($snapshot['outstanding'])->toHaveCount(1);
    expect($snapshot['outstanding']->first()->guest_name)->toBe('Frank Mike');
});

it('CheckedOut corp bookings still show in Dashboard outstanding until invoiced', function (): void {
    // Regression: after checking out a corp guest without settling
    // (invoice-later), the pulse tile + widget dropped the balance
    // entirely, so operators couldn't find the stay to invoice it.
    // Now CheckedOut corp bookings with no Issued/Paid invoice count.
    $corp = \App\Models\Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => \App\Enums\CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'balance_due'    => 90_000,
        'guest_name'     => 'Corp Guest',
        'corporate_id'   => $corp->id,
    ]);

    $snapshot = app(DashboardSnapshot::class)->build(bypass: true);

    expect($snapshot['kpis']['outstanding']['value'])->toBe(90_000);
    expect($snapshot['outstanding']->pluck('id'))->toContain($booking->id);
});

it('CheckedOut corp bookings drop out of Dashboard outstanding once invoiced', function (): void {
    // Once the corp invoice is Issued (or Paid) the stay's balance
    // is now tracked through the invoice ledger — surfacing it in
    // the "unpaid rooms" widget would double-count.
    $corp = \App\Models\Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => \App\Enums\CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'balance_due'    => 90_000,
        'guest_name'     => 'Corp Guest',
        'corporate_id'   => $corp->id,
    ]);
    $op = Operator::factory()->for($this->tenant)->create();
    app(\App\Actions\Invoicing\CreateInvoice::class)->handle(
        tenant: $this->tenant,
        booking: $booking,
        operator: $op,
        lines: [[
            'line_type' => \App\Enums\InvoiceLineType::Night->value,
            'description' => 'Room charge',
            'quantity' => 1,
            'unit_price' => 90_000,
        ]],
        issue: true,
    );

    $snapshot = app(DashboardSnapshot::class)->build(bypass: true);

    expect($snapshot['outstanding']->pluck('id'))->not->toContain($booking->id);
});
