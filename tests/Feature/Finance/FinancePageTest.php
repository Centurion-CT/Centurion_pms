<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Dashboard\Finance\Index as FinanceIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'fin-page']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'name' => 'Manager Mo',
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
    Cache::flush();
});

/* ---------- Access control ---------- */

it('renders the finance page for a manager', function (): void {
    $response = $this->actingAs($this->manager, 'web')
        ->get('http://fin-page.keverna.test/dashboard/finance');

    $response->assertOk();
    $response->assertSeeLivewire(FinanceIndex::class);
});

it('redirects a reservation officer away from the finance page', function (): void {
    $officer = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::ReservationOfficer,
    ]);

    $response = $this->actingAs($officer, 'web')
        ->get('http://fin-page.keverna.test/dashboard/finance');

    $response->assertRedirect();
});

it('redirects a frontdesk operator away from the finance page', function (): void {
    $frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);

    $response = $this->actingAs($frontdesk, 'web')
        ->get('http://fin-page.keverna.test/dashboard/finance');

    $response->assertRedirect();
});

/* ---------- KPI math ---------- */

it('computes billed, collected, refunds and outstanding correctly', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));

    // Two revenue-status bookings anchored in the current 30d window.
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-06-10',
        'total_amount' => 200_000,
        'balance_due' => 0,
        'source' => BookingSource::BookingCom,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-05',
        'total_amount' => 150_000,
        'balance_due' => 50_000, // this open booking contributes to outstanding
        'source' => BookingSource::Airbnb,
    ]);
    // Cancelled booking — excluded from Billed.
    Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create([
        'checkin_date' => '2030-06-11',
        'total_amount' => 999_999,
    ]);

    // Payments across the same window.
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'amount'  => 120_000,
        'paid_at' => Carbon::parse('2030-06-10 12:00:00'),
    ]);
    Payment::factory()->for($this->tenant)->create([
        'amount'  => 25_000,
        'status'  => PaymentStatus::Refunded,
        'type'    => PaymentType::Full,
        'paid_at' => Carbon::parse('2030-06-12 10:00:00'),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->assertSeeHtml('data-testid="kpi-billed"')
        ->assertSeeHtml('&#8358;350,000')      // billed = 200k + 150k
        ->assertSeeHtml('&#8358;120,000')      // collected
        ->assertSeeHtml('&#8358;25,000')       // refunded
        ->assertSeeHtml('&#8358;50,000');      // outstanding
});

it('splits revenue by channel', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-06-10',
        'total_amount' => 300_000,
        'source' => BookingSource::BookingCom,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-06-12',
        'total_amount' => 100_000,
        'source' => BookingSource::WalkIn,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->assertSeeHtml('data-testid="finance-channel-row-booking_com"')
        ->assertSeeHtml('data-testid="finance-channel-row-walk_in"')
        ->assertSeeHtml('Booking.com')
        ->assertSeeHtml('Walk-in');
});

it('persists preset and forecast horizon via URL params', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->assertSet('preset', 'last_30d')
        ->assertSet('forecastHorizon', 30)
        ->call('setPreset', 'this_month')
        ->assertSet('preset', 'this_month')
        ->call('setHorizon', 60)
        ->assertSet('forecastHorizon', 60)
        ->call('setHorizon', 999) // invalid, ignored
        ->assertSet('forecastHorizon', 60);
});
