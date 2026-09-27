<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Livewire\Admin\Revenue as AdminRevenue;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Admin\RevenueSnapshot;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config()->set('keverna.pricing.subscription_annual_ngn', 300_000);
    $this->admin = Admin::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('returns zeros when there is no data', function (): void {
    $snap = app(RevenueSnapshot::class)->build();

    expect($snap['active_subscriptions'])->toBe(0);
    expect($snap['arr_ngn'])->toBe(0);
    expect($snap['mrr_ngn'])->toBe(0);
    expect($snap['churn_pct'])->toBe(0.0);
});

it('computes ARR as active subscriptions × annual price', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    Subscription::factory()->for($tenantA)->create();
    Subscription::factory()->for($tenantB)->create();

    $snap = app(RevenueSnapshot::class)->build();

    expect($snap['active_subscriptions'])->toBe(2);
    expect($snap['arr_ngn'])->toBe(600_000);
    expect($snap['mrr_ngn'])->toBe(50_000);  // intdiv(600000, 12)
});

it('counts trial subscriptions as active for ARR purposes', function (): void {
    Subscription::factory()->for(Tenant::factory())->trial()->create();

    $snap = app(RevenueSnapshot::class)->build();
    expect($snap['active_subscriptions'])->toBe(1);
});

it('reports churn over the configured window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 10:00:00'));

    // 3 active + 1 churned in window = 25% churn
    Subscription::factory()->for(Tenant::factory())->create();
    Subscription::factory()->for(Tenant::factory())->create();
    Subscription::factory()->for(Tenant::factory())->create();

    $churnedTenant = Tenant::factory()->create();
    $churnedSub = Subscription::factory()->for($churnedTenant)->expired()->create();
    $churnedSub->forceFill(['updated_at' => Carbon::parse('2030-08-15 10:00:00')])->save();

    $snap = app(RevenueSnapshot::class)->build();

    expect($snap['churned_count'])->toBe(1);
    expect($snap['churn_pct'])->toBe(25.00);
});

it('ignores subscriptions churned BEFORE the window starts', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 10:00:00'));

    Subscription::factory()->for(Tenant::factory())->create();
    $oldChurn = Subscription::factory()->for(Tenant::factory())->expired()->create();
    // Update updated_at after factory creation (Subscription factory's expired() doesn't set updated_at)
    $oldChurn->forceFill(['updated_at' => Carbon::parse('2030-06-15 10:00:00')])->save();

    $snap = app(RevenueSnapshot::class)->build();

    expect($snap['churned_count'])->toBe(0);
});

it('sums Keverna + Paystack fees from succeeded payments in the window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 10:00:00'));

    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $room = Room::factory()->for($tenant)->for($property)->create();

    $booking = Booking::factory()->for($tenant)->for($room)->confirmed()->create([
        'keverna_fee' => 500,
        'paystack_fee' => 750,
    ]);
    Payment::factory()->for($tenant)->for($booking)->create([
        'status' => PaymentStatus::Succeeded,
    ]);
    // Failed payment shouldn't count.
    $booking2 = Booking::factory()->for($tenant)->for($room)->create([
        'keverna_fee' => 200, 'paystack_fee' => 300,
    ]);
    Payment::factory()->for($tenant)->for($booking2)->create([
        'status' => PaymentStatus::Failed,
    ]);
    Tenant::forgetCurrent();

    $snap = app(RevenueSnapshot::class)->build();

    expect($snap['keverna_fees_window_ngn'])->toBe(500);
    expect($snap['paystack_fees_window_ngn'])->toBe(750);
});

it('renders the revenue page for an authenticated admin', function (): void {
    Subscription::factory()->for(Tenant::factory())->create();
    Subscription::factory()->for(Tenant::factory())->create();

    $response = $this->actingAs($this->admin, 'admin')
        ->get('http://admin.keverna.test/revenue');

    $response->assertOk();
    $response->assertSeeLivewire(AdminRevenue::class);
    $response->assertSee('₦600,000');
});

it('rolls up Keverna Stays marketplace transactions separately from the platform total', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 10:00:00'));

    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $room = Room::factory()->for($tenant)->for($property)->create();

    // Two marketplace bookings + one tenant-direct booking.
    $mp1 = Booking::factory()->for($tenant)->for($room)->confirmed()->create([
        'total_amount' => 50_000, 'room_total' => 45_000,
        'keverna_fee' => 2_500, 'paystack_fee' => 750,
        'source_note' => 'Keverna Stays marketplace',
    ]);
    Payment::factory()->for($tenant)->for($mp1)->create([
        'status' => PaymentStatus::Succeeded, 'amount' => 50_000,
    ]);

    $mp2 = Booking::factory()->for($tenant)->for($room)->confirmed()->create([
        'total_amount' => 100_000, 'room_total' => 90_000,
        'keverna_fee' => 5_000, 'paystack_fee' => 1_500,
        'source_note' => 'Keverna Stays marketplace',
    ]);
    Payment::factory()->for($tenant)->for($mp2)->create([
        'status' => PaymentStatus::Succeeded, 'amount' => 100_000,
    ]);

    // Tenant-direct (walk-in tagged) - should NOT show up in stays.
    $direct = Booking::factory()->for($tenant)->for($room)->confirmed()->create([
        'total_amount' => 40_000, 'room_total' => 40_000,
        'keverna_fee' => 0, 'paystack_fee' => 0,
        'source_note' => 'Walk-in',
    ]);
    Payment::factory()->for($tenant)->for($direct)->create([
        'status' => PaymentStatus::Succeeded, 'amount' => 40_000,
    ]);
    Tenant::forgetCurrent();

    $snap = app(RevenueSnapshot::class)->build();

    // Stays-only rollup only counts the two marketplace bookings.
    expect($snap['stays_bookings_count'])->toBe(2);
    expect($snap['stays_gmv_window_ngn'])->toBe(150_000);
    expect($snap['stays_keverna_fees_window_ngn'])->toBe(7_500);
    expect($snap['stays_paystack_fees_window_ngn'])->toBe(2_250);
    // Operator payout = GMV - Keverna cut - Paystack cut.
    expect($snap['stays_operator_payouts_window_ngn'])->toBe(150_000 - 7_500 - 2_250);

    // Platform-total still includes all three bookings.
    expect($snap['keverna_fees_window_ngn'])->toBe(7_500);
    expect($snap['paystack_fees_window_ngn'])->toBe(2_250);
});

it('renders the marketplace section on /admin/revenue', function (): void {
    $response = $this->actingAs($this->admin, 'admin')
        ->get('http://admin.keverna.test/revenue');

    $response->assertOk()
        ->assertSee('data-testid="revenue-stays"', false)
        ->assertSee('data-testid="stays-gmv"', false)
        ->assertSee('data-testid="stays-keverna-fees"', false)
        ->assertSee('data-testid="stays-operator-payouts"', false)
        ->assertSee('Keverna Stays marketplace')
        ->assertSee('5% platform fee');
});

it('shows an empty-state hint when no marketplace bookings have settled yet', function (): void {
    $response = $this->actingAs($this->admin, 'admin')
        ->get('http://admin.keverna.test/revenue');

    $response->assertOk()
        ->assertSee('No Keverna Stays bookings settled in this window yet');
});

it('excludes marketplace bookings created OUTSIDE the churn window from the rollup', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-30 10:00:00'));

    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $room = Room::factory()->for($tenant)->for($property)->create();

    // Old marketplace booking - Payment created in June, well
    // before the 30-day window starts.
    $oldBooking = Booking::factory()->for($tenant)->for($room)->confirmed()->create([
        'total_amount' => 60_000, 'keverna_fee' => 3_000, 'paystack_fee' => 900,
        'source_note' => 'Keverna Stays marketplace',
    ]);
    $oldPayment = Payment::factory()->for($tenant)->for($oldBooking)->create([
        'status' => PaymentStatus::Succeeded, 'amount' => 60_000,
    ]);
    $oldPayment->forceFill(['created_at' => Carbon::parse('2030-06-01')])->save();
    Tenant::forgetCurrent();

    $snap = app(RevenueSnapshot::class)->build();

    expect($snap['stays_bookings_count'])->toBe(0);
    expect($snap['stays_gmv_window_ngn'])->toBe(0);
    expect($snap['stays_keverna_fees_window_ngn'])->toBe(0);
});
