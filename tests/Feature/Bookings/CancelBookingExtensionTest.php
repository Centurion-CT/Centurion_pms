<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingExtension;
use App\Actions\Bookings\ExtendBooking;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Reservations\Show as ReservationsShow;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Cancelling an unsettled stay extension: rolls the checkout back,
 * releases the extension nights from availability, deletes the
 * Extension charge. Refuses if the guest has already paid for it.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cancel-ext']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create();
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    Carbon::setTestNow(Carbon::create(2026, 9, 8, 10, 0, 0));

    // 2-night booking (nights = checkout minus checkin, no
    // overstay). Reserve the initial availability blocks so
    // ExtendBooking has real state to work against.
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'nights' => 2,
        'total_amount' => 100_000, 'room_total' => 100_000,
        'balance_due' => 0,
    ]);
    foreach ([Carbon::today()->subDay(), Carbon::today()] as $d) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->room->id,
            'room_category_id' => $this->category->id,
            'date' => $d->format('Y-m-d H:i:s'),
            'type' => \App\Enums\AvailabilityBlockType::Booked->value,
            'booking_id' => $this->booking->id,
        ]);
    }
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('reverses everything ExtendBooking did on an unsettled extension', function (): void {
    // Extend by 2 nights.
    $newCheckout = Carbon::today()->addDays(3);
    app(ExtendBooking::class)->handle($this->booking, $newCheckout, $this->operator);

    $extended = $this->booking->refresh();
    expect($extended->nights)->toBe(4);
    expect($extended->checkout_date->toDateString())->toBe($newCheckout->toDateString());
    expect((int) $extended->total_amount)->toBe(200_000);

    $extensionCharge = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();

    // Cancel it.
    app(CancelBookingExtension::class)->handle($extensionCharge, $this->operator);

    $reset = $this->booking->refresh();
    expect($reset->nights)->toBe(2);
    expect($reset->checkout_date->toDateString())->toBe(Carbon::today()->addDay()->toDateString());
    expect((int) $reset->total_amount)->toBe(100_000);
    expect((int) $reset->room_total)->toBe(100_000);

    // Extension charge gone.
    expect(BookingCharge::query()->find($extensionCharge->id))->toBeNull();

    // Extension nights released - only the original 2 nights
    // survive as Booked rows.
    $blocks = AvailabilityBlock::query()
        ->where('booking_id', $this->booking->id)->get();
    expect($blocks)->toHaveCount(2);
});

it('refuses to cancel a settled extension', function (): void {
    app(ExtendBooking::class)->handle($this->booking, Carbon::today()->addDays(3), $this->operator);
    $charge = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();
    $charge->forceFill(['settled' => true, 'settled_at' => Carbon::now(), 'settled_method' => 'cash'])->save();

    expect(fn () => app(CancelBookingExtension::class)->handle($charge, $this->operator))
        ->toThrow(RuntimeException::class, 'already been paid');

    // Nothing rolled back.
    $b = $this->booking->refresh();
    expect($b->nights)->toBe(4);
});

it('refuses to cancel a non-extension charge (e.g. Overstay, RoomService)', function (): void {
    $roomService = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::RoomService->value,
        'description' => 'Beer × 2',
        'amount' => 4_000,
        'settled' => false,
    ]);

    expect(fn () => app(CancelBookingExtension::class)->handle($roomService, $this->operator))
        ->toThrow(RuntimeException::class, 'Only Extension charges');

    expect(BookingCharge::query()->find($roomService->id))->not->toBeNull();
});

it('booking-show renders a cancel-extension button next to an unsettled Extension charge', function (): void {
    app(ExtendBooking::class)->handle($this->booking, Carbon::today()->addDays(3), $this->operator);
    $charge = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertSee('data-testid="cancel-extension-'.$charge->id.'"', false);
});

it('does NOT render a cancel-extension button on a non-extension charge', function (): void {
    $roomService = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::RoomService->value,
        'description' => 'Laundry',
        'amount' => 2_500,
        'settled' => false,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertDontSee('data-testid="cancel-extension-'.$roomService->id.'"', false);
});

it('does NOT render the button on a settled extension (paid → refund workflow)', function (): void {
    app(ExtendBooking::class)->handle($this->booking, Carbon::today()->addDays(3), $this->operator);
    $charge = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();
    $charge->forceFill(['settled' => true, 'settled_at' => Carbon::now(), 'settled_method' => 'cash'])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertDontSee('data-testid="cancel-extension-'.$charge->id.'"', false);
});

it('reverses a post-checkin discount applied AFTER the extension (regression: manual_discount growing on cancel)', function (): void {
    // Real-world scenario the user hit:
    //   - 1-night booking, manual discount stamped at creation.
    //   - Operator extends by 1 night.
    //   - Operator gives a "sorry" post-checkin discount on the
    //     extension.
    //   - Operator cancels the extension - manual_discount_amount
    //     should snap back to what it was pre-extension.
    $this->booking->forceFill([
        'total_amount' => 89_810,          // net-of-original ₦10,190 discount
        'room_total'   => 100_000,
        'manual_discount_amount' => 10_190,
        'balance_due'  => 89_810,
    ])->save();

    // Extend by 1 night at ₦50,000/night (breakdown will use the
    // category's ₦50,000 base_rate from beforeEach).
    app(ExtendBooking::class)->handle(
        $this->booking, Carbon::today()->addDays(2), $this->operator,
    );
    $b = $this->booking->refresh();
    expect((int) $b->total_amount)->toBe(89_810 + 50_000); // ₦139,810
    expect((int) $b->manual_discount_amount)->toBe(10_190);

    // Post-checkin discount on the extended stay.
    app(\App\Actions\Bookings\ApplyPostCheckinDiscount::class)->handle(
        $b, $this->operator,
        \App\Enums\ManualDiscountType::Fixed,
        percent: 0.0, fixedAmount: 5_000,
    );
    $b = $b->refresh();
    expect((int) $b->total_amount)->toBe(139_810 - 5_000); // ₦134,810
    expect((int) $b->manual_discount_amount)->toBe(10_190 + 5_000); // ₦15,190

    // Cancel the extension.
    $charge = BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();
    app(CancelBookingExtension::class)->handle($charge, $this->operator);

    $reset = $b->refresh();
    // Extension itself reversed.
    expect($reset->nights)->toBe(2);
    expect((int) $reset->room_total)->toBe(100_000);
    // Manual discount rewound to pre-extend value.
    expect((int) $reset->manual_discount_amount)->toBe(10_190);
    // total_amount: pre-extend was ₦89,810. After extend + discount
    // + cancel-with-reversal we should be back to ₦89,810.
    expect((int) $reset->total_amount)->toBe(89_810);
    // balance_due is deliberately NOT restored on cancel. See the
    // action's comment - reversing the discount off balance_due
    // requires a snapshot of the pre-discount balance to avoid
    // over-restoring already-paid bookings. Here ApplyPostCheckinDiscount
    // pulled ₦5,000 off the ₦89,810 balance, so we're left with
    // ₦84,810 owed. The operator can add a charge if that's genuinely
    // owed; leaving the state under-restored is the safer default.
    expect((int) $reset->balance_due)->toBe(84_810);
});

it('leaves manual_discount untouched when the discount was applied BEFORE the extension', function (): void {
    // Operator gave the discount BEFORE extending; that discount is
    // orthogonal to the extension and must NOT get reversed on cancel.
    $this->booking->forceFill([
        'total_amount' => 90_000,
        'room_total'   => 100_000,
        'manual_discount_amount' => 10_000,
        'balance_due'  => 90_000,
    ])->save();

    app(\App\Actions\Bookings\ApplyPostCheckinDiscount::class)->handle(
        $this->booking->refresh(), $this->operator,
        \App\Enums\ManualDiscountType::Fixed,
        percent: 0.0, fixedAmount: 3_000,
    );
    $b = $this->booking->refresh();
    expect((int) $b->manual_discount_amount)->toBe(13_000);

    // Extend AFTER the discount.
    Carbon::setTestNow(Carbon::now()->addMinute());
    app(ExtendBooking::class)->handle($b, Carbon::today()->addDays(2), $this->operator);

    // Cancel.
    $charge = BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();
    app(CancelBookingExtension::class)->handle($charge, $this->operator);

    // Pre-extend discount untouched.
    expect((int) $b->refresh()->manual_discount_amount)->toBe(13_000);
});

it('Livewire cancelExtension wires through to the action + surfaces a notify', function (): void {
    app(ExtendBooking::class)->handle($this->booking, Carbon::today()->addDays(3), $this->operator);
    $charge = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('cancelExtension', $charge->id)
        ->assertDispatched('notify');

    expect(BookingCharge::query()->find($charge->id))->toBeNull();
    expect($this->booking->refresh()->nights)->toBe(2);
});
