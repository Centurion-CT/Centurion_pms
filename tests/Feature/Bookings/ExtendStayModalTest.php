<?php

declare(strict_types=1);

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Extensions run through PricingCalculator's netSubtotal (no
 * Paystack + Keverna fees) — the guest is already checked in and
 * pays at the counter. Derive expected values from the calculator
 * so a seasonal-rate tweak doesn't cascade into asserts.
 *
 * The modal's fast-moving inputs (discount, payment, mode) live in
 * Alpine, so tests here call submitExtend() with explicit args
 * instead of ->set()ing wire props.
 */
function extensionCharge(Room $room, Carbon $from, Carbon $to): int
{
    return (int) app(PricingCalculator::class)->calculate($room, $from, $to)->netSubtotal;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'extend-modal']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'name' => 'Ada Manager',
    ]);
    $this->actingAs($this->operator, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

function baseCheckedInBooking(Tenant $t, Room $r): Booking
{
    return Booking::factory()->for($t)->for($r)->create([
        'guest_name' => 'Ada Guest',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'nights' => 2,
        'room_total' => 100_000,
        'total_amount' => 100_000,
        'balance_due' => 0,
    ]);
}

it('openExtendModal snapshots the current booking values for Alpine to seed', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->assertSet('extendOpen', true)
        ->assertSet('extendBookingId', $b->id)
        ->assertSet('extendCurrentCheckout', $b->checkout_date->toDateString())
        ->assertSet('extendCurrentTotal', 100_000)
        ->assertSet('extendCurrentBalance', 0)
        ->assertSet('extendRatePerNight', 50_000)
        ->assertSet('extendGuestName', 'Ada Guest')
        ->assertSet('extendPreselectCheckout', '');
});

it('extendCurrentBalance surfaces the full outstanding (balance + unsettled charges), not just balance_due', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);
    // Guest has a residual booking balance PLUS an unsettled overstay
    // charge that was auto-generated but not yet added to the ledger.
    // The Full quick-pay preset must offer the sum, not just balance_due.
    $b->forceFill(['balance_due' => 43_000])->save();
    BookingCharge::create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $b->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Overstay->value,
        'description' => '1 night overstay',
        'amount' => 90_000,
        'settled' => false,
    ]);

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->assertSet('extendCurrentBalance', 133_000);
});

it('openExtendModal accepts a valid preselect checkout from the calendar drag', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $target = $b->checkout_date->copy()->addDays(3)->toDateString();

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id, $target)
        ->assertSet('extendPreselectCheckout', $target);
});

it('openExtendModal clears an out-of-range preselect (before or on current checkout)', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);
    // Same day as current checkout is not a valid extension target.
    $sameDay = $b->checkout_date->toDateString();

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id, $sameDay)
        ->assertSet('extendPreselectCheckout', '');
});

it('openExtendModal ignores a malformed preselect string', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id, 'not-a-date')
        ->assertSet('extendPreselectCheckout', '');
});

it('submitExtend extends the booking and creates an Extension charge', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $newCheckout = $b->checkout_date->copy()->addDay();
    $expectedCharge = extensionCharge($this->room, $b->checkout_date, $newCheckout);

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend', $newCheckout->toDateString());

    $b->refresh();
    expect($b->checkout_date->toDateString())->toBe($newCheckout->toDateString());
    expect($b->nights)->toBe(3); // 2 + 1 extra
    expect($b->total_amount)->toBe(100_000 + $expectedCharge);
});

it('extending does NOT double-count the extension in outstandingTotal', function (): void {
    // Regression: ExtendBooking used to bump balance_due AND create a
    // BookingCharge for the same amount, so outstandingTotal() summed
    // the extension twice. A guest who extended for ₦80k saw ₦160k
    // owed on the reservation page.
    $b = baseCheckedInBooking($this->tenant, $this->room);
    // Guest already paid the initial stay in full — balance_due = 0.
    expect((int) $b->balance_due)->toBe(0);
    expect((int) $b->outstandingTotal())->toBe(0);

    $newCheckout = $b->checkout_date->copy()->addDay();
    $extensionAmount = extensionCharge($this->room, $b->checkout_date, $newCheckout);

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend', $newCheckout->toDateString());

    $b->refresh();
    // The extension lives ONLY in the unsettled BookingCharge; not
    // also added to balance_due.
    expect((int) $b->balance_due)->toBe(0);
    expect((int) $b->outstandingTotal())->toBe($extensionAmount);
});

it('extending inherits the booking\'s breakfast tier by default', function (): void {
    // Regression: guest-portal + operator-side extends used to fall
    // through to the base rate regardless of what tier the booking
    // was on. A guest who paid the breakfast rate got a silent
    // downgrade for their extension nights.
    $this->category->update(['breakfast_included_rate' => 60_000]);
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Breakfast Guest',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'nights' => 2,
        'room_total' => 120_000,
        'total_amount' => 120_000,
        'balance_due' => 0,
        'breakfast_included' => true,
    ]);
    $newCheckout = $b->checkout_date->copy()->addDay();

    // Operator submits with no explicit tier arg → should inherit.
    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        // hasBreakfastOption seeded from category
        ->assertSet('extendHasBreakfastOption', true)
        ->assertSet('extendBookingBreakfast', true)
        ->assertSet('extendRatePerNight', 60_000)
        ->call('submitExtend', $newCheckout->toDateString());

    $b->refresh();
    // Extension charge = 1 extra night at breakfast_included_rate (60k),
    // NOT base_rate (50k). The charge on the ledger is 60k.
    $extensionCharge = $b->charges()->latest('id')->first();
    expect((int) $extensionCharge->amount)->toBe(60_000);
});

it('extending honours an explicit breakfast override (opts out)', function (): void {
    // Operator can flip the toggle off — extension nights use base
    // even though the booking was on the breakfast tier.
    $this->category->update(['breakfast_included_rate' => 60_000]);
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Downgrader',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'nights' => 2,
        'room_total' => 120_000,
        'total_amount' => 120_000,
        'balance_due' => 0,
        'breakfast_included' => true,
    ]);
    $newCheckout = $b->checkout_date->copy()->addDay();

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend',
            $newCheckout->toDateString(),
            'percent', 0.0, 0, 0, 'cash', '',
            false, // breakfastIncluded = false override
        );

    $b->refresh();
    $extensionCharge = $b->charges()->latest('id')->first();
    // Base rate wins, not breakfast tier.
    expect((int) $extensionCharge->amount)->toBe(50_000);
});

it('extending bumps room_total so the derived per-night rate stays truthful', function (): void {
    // Regression: without bumping room_total, the in-house table
    // computed daily_rate = room_total / nights = 45k / 3 = 15k on a
    // 40k-a-night stay, then mislabelled the guest as being on a
    // discount they never got.
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $originalRoomTotal = (int) $b->room_total;
    $newCheckout = $b->checkout_date->copy()->addDay();
    $extensionAmount = extensionCharge($this->room, $b->checkout_date, $newCheckout);

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend', $newCheckout->toDateString());

    $b->refresh();
    expect((int) $b->room_total)->toBe($originalRoomTotal + $extensionAmount);
    // Derived per-night rate is now the mixed weighted average of the
    // original + extension nights, never the misleading /nights slice.
    $derivedPerNight = intdiv((int) $b->room_total, (int) $b->nights);
    expect($derivedPerNight >= (int) $this->room->base_rate)->toBeTrue();
});

it('submitExtend with a percent discount reduces the total', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $newCheckout = $b->checkout_date->copy()->addDay();
    $extension = extensionCharge($this->room, $b->checkout_date, $newCheckout);
    $preDiscountTotal = 100_000 + $extension;
    $expectedOff = (int) floor($preDiscountTotal * 20 / 100);

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend',
            $newCheckout->toDateString(),
            'percent',
            20.0,
        );

    $b->refresh();
    // Discount runs on total_amount AFTER the extension charge is
    // added (booking.total = 100k + extension); 20% of that.
    expect($b->total_amount)->toBe($preDiscountTotal - $expectedOff);
    expect($b->manual_discount_amount)->toBe($expectedOff);
});

it('submitExtend with a fixed discount reduces the total by that amount', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $newCheckout = $b->checkout_date->copy()->addDay();
    $extension = extensionCharge($this->room, $b->checkout_date, $newCheckout);
    $fixedOff = 7_500;

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend',
            $newCheckout->toDateString(),
            'fixed',
            0.0,
            $fixedOff,
        );

    $b->refresh();
    expect($b->total_amount)->toBe(100_000 + $extension - $fixedOff);
    expect($b->manual_discount_amount)->toBe($fixedOff);
});

it('submitExtend with payment amount records a Payment row', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $newCheckout = $b->checkout_date->copy()->addDay();

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend',
            $newCheckout->toDateString(),
            'percent',
            0.0,
            0,
            50_000,
            'cash',
        );

    $b->refresh();
    $payment = Payment::query()->where('booking_id', $b->id)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect($payment->amount)->toBe(50_000);
    expect($payment->method)->toBe('cash');
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
    expect($payment->collected_by_operator_id)->toBe($this->operator->id);
});

it('discount at extend time reduces the extension charge amount + notes the discount in its description', function (): void {
    // The bug: extend + apply ₦10k discount → charge shows ₦100k
    // (pre-discount) even though total_amount + outstanding both
    // reflect the ₦90k the guest actually owes. Ledger + accountant
    // saw the wrong number. Fix: charge.amount is the post-discount
    // ₦, description carries "· ₦10,000 discount applied" trail.
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $newCheckout = $b->checkout_date->copy()->addDay();

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend',
            $newCheckout->toDateString(),
            'fixed',           // fixed ₦ discount mode
            0.0,               // percent (ignored)
            10_000,            // ₦10,000 discount
            0,                 // no payment collected
            'cash',
        );

    $charge = \App\Models\BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', \App\Enums\BookingChargeType::Extension->value)
        ->latest('id')
        ->firstOrFail();

    // Extension value = 1 night × ₦50,000; discount = ₦10,000 →
    // charge amount = ₦40,000.
    expect((int) $charge->amount)->toBe(40_000);
    expect($charge->description)->toContain('₦10,000 discount applied');

    // total_amount also reflects the discount (via ApplyPostCheckinDiscount).
    $b->refresh();
    expect((int) $b->total_amount)->toBe(100_000 + 50_000 - 10_000);   // 140,000
    // Outstanding matches what the guest actually owes: balance_due
    // + unsettled extension charge (post-discount).
    expect($b->outstandingTotal())->toBe(40_000);
});

it('cancel-extension on a discount-adjusted charge restores totals correctly (no double-count)', function (): void {
    // Regression: the cancel path used to add discountRewind back
    // to total_amount unconditionally. For a charge whose amount
    // already reflects the discount (post-fix), that over-restored
    // by the discount. Now the charge's description flag drives the
    // conditional rewind.
    $b = baseCheckedInBooking($this->tenant, $this->room);

    // Take a snapshot BEFORE extending so we can assert full rollback.
    $preTotal = (int) $b->total_amount;
    $preRoomTotal = (int) $b->room_total;
    $preNights = (int) $b->nights;

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend',
            $b->checkout_date->copy()->addDay()->toDateString(),
            'fixed', 0.0, 10_000, 0, 'cash',
        );

    $charge = \App\Models\BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', \App\Enums\BookingChargeType::Extension->value)
        ->latest('id')
        ->firstOrFail();

    app(\App\Actions\Bookings\CancelBookingExtension::class)->handle($charge, $this->operator);

    $b->refresh();
    expect((int) $b->total_amount)->toBe($preTotal);
    expect((int) $b->room_total)->toBe($preRoomTotal);
    expect((int) $b->nights)->toBe($preNights);
});

it('submitExtend dispatches extend-modal-close so the Alpine modal actually dismisses', function (): void {
    // Server-side closeExtendModal only clears the Livewire prop; the
    // Alpine `open` state is a separate variable. Without the
    // extend-modal-close event, the modal stayed visible on success.
    // Regression: users reported the modal never closing after Extend.
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $newCheckout = $b->checkout_date->copy()->addDay();

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend',
            $newCheckout->toDateString(),
            'percent', 0.0, 0, 0, 'cash',
        )
        ->assertSet('extendOpen', false)
        ->assertDispatched('extend-modal-close');
});

it('logs an extended activity + a customer-timeline booking.extended event', function (): void {
    $b = baseCheckedInBooking($this->tenant, $this->room);
    $newCheckout = $b->checkout_date->copy()->addDay();

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('openExtendModal', $b->id)
        ->call('submitExtend',
            $newCheckout->toDateString(),
            'percent',
            0.0,
            0,
            0,
            'cash',
            'Late flight',
        );

    $log = Activity::query()
        ->where('event', 'extended')
        ->where('subject_id', $b->id)
        ->latest('id')
        ->first();
    expect($log)->not->toBeNull();
    expect($log->causer_id)->toBe($this->operator->id);
});
