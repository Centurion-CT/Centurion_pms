<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Dashboard\Accounting\Revenue\Index as RevenueIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Accounting → Revenue: period totals, branch scoping, CSV export.
 * Layered on top of FinanceSnapshot so the numbers here always
 * agree with the /dashboard/finance surface.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2027-06-15 12:00:00');
    $this->tenant = Tenant::factory()->create(['slug' => 'rev-snap']);
    $this->tenant->makeCurrent();
    $this->propertyA = Property::factory()->for($this->tenant)->create(['name' => 'A']);
    $this->propertyB = Property::factory()->for($this->tenant)->create(['name' => 'B']);
    $this->catA = RoomCategory::factory()->for($this->tenant)->for($this->propertyA)->create(['base_rate' => 40_000]);
    $this->catB = RoomCategory::factory()->for($this->tenant)->for($this->propertyB)->create(['base_rate' => 50_000]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->propertyA)->for($this->catA, 'category')->create();
    $this->roomB = Room::factory()->for($this->tenant)->for($this->propertyB)->for($this->catB, 'category')->create();

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($this->manager, 'web');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function switchToProperty(Property $p): void
{
    app(\App\Services\Properties\PropertyContext::class)->switchTo($p);
}

function seedBookingWithPayment(Tenant $t, Room $room, int $amount, string $paidAt): Booking
{
    $b = Booking::factory()->for($t)->for($room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::parse($paidAt)->toDateString(),
        'checkout_date' => Carbon::parse($paidAt)->addDay()->toDateString(),
        'nights' => 1,
        'total_amount' => $amount,
        'room_total' => $amount,
    ]);
    Payment::query()->create([
        'tenant_id' => $t->id,
        'booking_id' => $b->id,
        'amount' => $amount,
        'type' => PaymentType::Balance,
        'method' => 'cash',
        'status' => PaymentStatus::Succeeded,
        'paid_at' => $paidAt,
        'paystack_reference' => 'seed-'.uniqid(),
    ]);
    return $b;
}

it('month range totals reflect collected payments in-window (single branch)', function (): void {
    switchToProperty($this->propertyA);
    // Two June payments + one May payment (outside the month window).
    seedBookingWithPayment($this->tenant, $this->roomA, 40_000, '2027-06-05 10:00:00');
    seedBookingWithPayment($this->tenant, $this->roomA, 60_000, '2027-06-10 10:00:00');
    seedBookingWithPayment($this->tenant, $this->roomA, 99_000, '2027-05-28 10:00:00');

    Livewire::test(RevenueIndex::class)
        ->set('range', 'month')
        ->assertViewHas('kpis', fn ($k) => (int) $k['collected'] === 100_000);
});

it('branch scope: switching branches changes the totals', function (): void {
    seedBookingWithPayment($this->tenant, $this->roomA, 40_000, '2027-06-08 09:00:00');
    seedBookingWithPayment($this->tenant, $this->roomB, 55_000, '2027-06-08 09:00:00');

    switchToProperty($this->propertyA);
    Livewire::test(RevenueIndex::class)
        ->assertViewHas('kpis', fn ($k) => (int) $k['collected'] === 40_000);

    switchToProperty($this->propertyB);
    Livewire::test(RevenueIndex::class)
        ->assertViewHas('kpis', fn ($k) => (int) $k['collected'] === 55_000);
});

it('range picker switches to a today-only window', function (): void {
    switchToProperty($this->propertyA);
    // Today = 15 June. Only the 15 June payment should show under Today.
    seedBookingWithPayment($this->tenant, $this->roomA, 30_000, '2027-06-15 09:00:00');
    seedBookingWithPayment($this->tenant, $this->roomA, 90_000, '2027-06-14 09:00:00');

    Livewire::test(RevenueIndex::class)
        ->call('pickRange', 'today')
        ->assertSet('range', 'today')
        ->assertViewHas('kpis', fn ($k) => (int) $k['collected'] === 30_000);
});

it('custom range honours from/to dates', function (): void {
    switchToProperty($this->propertyA);
    seedBookingWithPayment($this->tenant, $this->roomA, 12_500, '2027-06-01 10:00:00');
    seedBookingWithPayment($this->tenant, $this->roomA, 47_500, '2027-06-03 10:00:00');
    seedBookingWithPayment($this->tenant, $this->roomA, 80_000, '2027-06-10 10:00:00'); // outside

    Livewire::test(RevenueIndex::class)
        ->call('pickRange', 'custom')
        ->set('customFrom', '2027-06-01')
        ->set('customTo', '2027-06-05')
        ->assertViewHas('kpis', fn ($k) => (int) $k['collected'] === 60_000);
});

it('CSV export streams the daily rows for the picked range', function (): void {
    switchToProperty($this->propertyA);
    seedBookingWithPayment($this->tenant, $this->roomA, 40_000, '2027-06-05 10:00:00');

    $response = $this->get(route('tenant.accounting.revenue.export', ['range' => 'month']));
    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Date')->toContain('Collected')->toContain('40000');
});
