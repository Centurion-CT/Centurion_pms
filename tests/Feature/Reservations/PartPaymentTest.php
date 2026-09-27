<?php

declare(strict_types=1);

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Part-payment flow on the reservation detail: guest can't clear
 * the full outstanding at the counter, operator books what they
 * hand over now, remainder rides on balance_due for the next
 * attempt.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'part-pay']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    // In-house guest with an unsettled ₦20k extension charge -
    // outstanding = ₦20k, balance_due = 0 (charge-only owed).
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Ada Part',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'nights' => 2,
        'total_amount' => 120_000, 'room_total' => 100_000,
        'balance_due' => 20_000,
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Extension night',
        'amount' => 20_000, 'settled' => false,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('applies the part payment to balance_due first, then chips unsettled charges', function (): void {
    // Setup: balance_due=₦20k, one unsettled charge=₦20k, outstanding=₦40k.
    // Guest hands over ₦25k: ₦20k clears balance_due, remaining
    // ₦5k trims the charge from ₦20k to ₦15k.
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 25_000)
        ->set('partPayMethod', 'cash')
        ->call('takePartPayment')
        ->assertDispatched('notify');

    $payment = Payment::query()->where('booking_id', $this->booking->id)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect((int) $payment->amount)->toBe(25_000);
    expect($payment->method)->toBe('cash');
    expect($payment->status)->toEqual(PaymentStatus::Succeeded);

    $b = $this->booking->refresh();
    expect((int) $b->balance_due)->toBe(0);
    // Charge trimmed by the ₦5k remainder.
    $charge = BookingCharge::query()->where('booking_id', $b->id)->first();
    expect((int) $charge->amount)->toBe(15_000);
    expect((bool) $charge->settled)->toBeFalse();
    // Outstanding dropped by exactly ₦25k (the payment amount).
    expect($b->outstandingTotal())->toBe(15_000);
});

it('fully settles a charge when the remainder covers it exactly', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 40_000)  // exactly the outstanding
        ->set('partPayMethod', 'cash')
        ->call('takePartPayment');

    $b = $this->booking->refresh();
    expect((int) $b->balance_due)->toBe(0);
    $charge = BookingCharge::query()->where('booking_id', $b->id)->first();
    expect((bool) $charge->settled)->toBeTrue();
    expect($charge->settled_method)->toBe('part_payment');
    expect($b->outstandingTotal())->toBe(0);
});

it('leaves charges intact when the payment fits inside balance_due', function (): void {
    // Payment smaller than balance_due should only reduce balance_due.
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 10_000)
        ->set('partPayMethod', 'cash')
        ->call('takePartPayment');

    $b = $this->booking->refresh();
    expect((int) $b->balance_due)->toBe(10_000);
    // Charge stays unsettled + untouched.
    $charge = BookingCharge::query()->where('booking_id', $b->id)->first();
    expect((int) $charge->amount)->toBe(20_000);
    expect((bool) $charge->settled)->toBeFalse();
    expect($b->outstandingTotal())->toBe(30_000);
});

it('chips charges when balance_due is 0 (outstanding is charges-only)', function (): void {
    // Reproduces the reported bug: outstanding = ₦45k (all from an
    // unsettled charge), no balance_due. Part payment of ₦23k should
    // trim the charge to ₦22k and drop outstanding to ₦22k.
    $this->booking->forceFill([
        'balance_due' => 0,
        'total_amount' => 45_000,
    ])->save();
    BookingCharge::query()->where('booking_id', $this->booking->id)->delete();
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Extension',
        'amount' => 45_000, 'settled' => false,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 23_000)
        ->set('partPayMethod', 'cash')
        ->call('takePartPayment');

    $b = $this->booking->refresh();
    expect((int) $b->balance_due)->toBe(0);
    $charge = BookingCharge::query()->where('booking_id', $b->id)->first();
    expect((int) $charge->amount)->toBe(22_000);
    // Outstanding dropped by exactly the ₦23k paid.
    expect($b->outstandingTotal())->toBe(22_000);
});

it('records a Payment row even when total_amount is already fully paid (charge-only outstanding)', function (): void {
    // Regression: the user reported the Payments page showing only
    // the original ₦45k Full payment, missing the ₦23k part payment
    // taken later. Root cause: RecordManualPayment's idempotency
    // guard compares alreadyPaid vs booking.total_amount. Extension
    // charges DON'T bump total_amount (they live in booking_charges),
    // so a booking already paid in full for its base stay would
    // silently drop the receipt. Fix: bypass the guard for part
    // payments.
    $this->booking->forceFill([
        'total_amount' => 45_000,
        'balance_due' => 0,
    ])->save();
    // Simulate the "base stay already fully paid" state.
    Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'paystack_reference' => 'seed-'.uniqid(),
        'amount' => 45_000,
        'type' => \App\Enums\PaymentType::Full,
        'method' => 'cash',
        'status' => PaymentStatus::Succeeded,
        'paid_at' => now(),
    ]);
    // Then an extension charge lands.
    BookingCharge::query()->where('booking_id', $this->booking->id)->delete();
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Extension night',
        'amount' => 45_000, 'settled' => false,
    ]);

    // Part payment of ₦23k.
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 23_000)
        ->set('partPayMethod', 'cash')
        ->call('takePartPayment');

    // The seed Payment plus a fresh part-payment row.
    $payments = Payment::query()->where('booking_id', $this->booking->id)->orderBy('id')->get();
    expect($payments)->toHaveCount(2);

    $part = $payments->last();
    expect((int) $part->amount)->toBe(23_000);
    expect($part->method)->toBe('cash');
    expect($part->status)->toEqual(PaymentStatus::Succeeded);

    // Charge trims to ₦22k.
    $charge = BookingCharge::query()->where('booking_id', $this->booking->id)->first();
    expect((int) $charge->amount)->toBe(22_000);
});

it('stamps the description with the running total when a charge is partially absorbed', function (): void {
    // First trim: ₦23k on a ₦45k charge, description gets the trail.
    // Second trim: ₦5k on the ₦22k remainder, description should walk
    // back to the original ₦45k for the "of X originally" suffix so
    // the operator sees the true cumulative received amount.
    $this->booking->forceFill(['balance_due' => 0, 'total_amount' => 45_000])->save();
    BookingCharge::query()->where('booking_id', $this->booking->id)->delete();
    $charge = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Stay extended · 1 extra night',
        'amount' => 45_000, 'settled' => false,
    ]);

    // First trim
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 23_000)
        ->set('partPayMethod', 'cash')
        ->call('takePartPayment');

    $charge->refresh();
    expect((int) $charge->amount)->toBe(22_000);
    expect($charge->description)->toContain('part-paid ₦23,000 of ₦45,000 originally');

    // Second trim - should cumulate
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 5_000)
        ->set('partPayMethod', 'cash')
        ->call('takePartPayment');

    $charge->refresh();
    expect((int) $charge->amount)->toBe(17_000);
    // Cumulated to ₦28k received against the original ₦45k.
    expect($charge->description)->toContain('part-paid ₦28,000 of ₦45,000 originally');
    // No duplicate part-paid trails.
    expect(substr_count($charge->description, 'part-paid'))->toBe(1);
});

it('refuses a part-payment amount larger than the outstanding', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 999_999)
        ->call('takePartPayment')
        ->assertDispatched('notify');

    expect(Payment::query()->where('booking_id', $this->booking->id)->count())->toBe(0);
    expect((int) $this->booking->refresh()->balance_due)->toBe(20_000);
});

it('refuses an amount of ₦0', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 0)
        ->call('takePartPayment')
        ->assertDispatched('notify');

    expect(Payment::query()->where('booking_id', $this->booking->id)->count())->toBe(0);
});

it('booking is fully settled when the part payment equals the outstanding', function (): void {
    // Take exactly the outstanding.
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 20_000)
        ->call('takePartPayment');

    $b = $this->booking->refresh();
    expect((int) $b->balance_due)->toBe(0);
});

it('renders a Part payment entry in the timeline after takePartPayment', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('togglePartPay')
        ->set('partPayAmount', 5_000)
        ->set('partPayMethod', 'transfer')
        ->call('takePartPayment')
        ->assertSee('Part payment')
        ->assertSee('₦5,000 · transfer');
});

it('renders the toggle button + inline form when the operator clicks it', function (): void {
    $r = Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref]);
    $r->assertSee('data-testid="toggle-part-pay"', false)
        ->assertDontSee('data-testid="part-pay-panel"', false)
        ->call('togglePartPay')
        ->assertSee('data-testid="part-pay-panel"', false);
});
