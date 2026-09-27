<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\DiscountType;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Regression — the quick-reserve sheet on the Reservations page was
 * hardcoding autoApplyDiscounts: false, so operators who set up an
 * auto-apply discount (code IS NULL) never saw it attach to a booking
 * made from the sheet. Every other reservation surface (ManualCreate,
 * PropertyView, Home) already opted in via discountSubmissionArgs().
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'quick-reserve-disc']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 4, 'capacity_children' => 2,
        'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('applies an auto-apply discount whose channels include walk_in', function (): void {
    // 10% off, no code, walk_in channel — quick-reserve fires with
    // BookingSource::WalkIn under the hood.
    Discount::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Front-desk 10%',
        'code' => null,
        'type' => DiscountType::Percent,
        'value' => 10,
        'channels' => [BookingSource::WalkIn->value],
        'active' => true,
    ]);

    $checkin  = Carbon::today()->addDay();
    $checkout = Carbon::today()->addDays(3);   // 2 nights × ₦50,000 = ₦100,000

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Chi Guest')
        ->set('reservePhone', '08012345678')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', $checkin->toDateString())
        ->set('reserveCheckout', $checkout->toDateString())
        ->set('reserveAdults', 2)
        ->set('reservePaymentStatus', 'confirmed')
        ->call('submitReserve')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect($booking)->not->toBeNull();
    // Room total is discounted 10% off ₦100,000 → ₦90,000 net.
    expect((int) $booking->room_total)->toBe(90_000);
});

it('does NOT apply a discount whose channels exclude walk_in', function (): void {
    // Online-only rule — quick-reserve uses walk_in, must not attach.
    Discount::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Website 10%',
        'code' => null,
        'type' => DiscountType::Percent,
        'value' => 10,
        'channels' => [BookingSource::Online->value],
        'active' => true,
    ]);

    $checkin  = Carbon::today()->addDay();
    $checkout = Carbon::today()->addDays(3);

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Chi Guest')
        ->set('reservePhone', '08012345678')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', $checkin->toDateString())
        ->set('reserveCheckout', $checkout->toDateString())
        ->set('reserveAdults', 2)
        ->set('reservePaymentStatus', 'confirmed')
        ->call('submitReserve')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect((int) $booking->room_total)->toBe(100_000); // full price
});

it('applies the breakfast tier when reserveBreakfastIncluded is on', function (): void {
    // Extend the base category with a breakfast rate.
    $this->category->update(['breakfast_included_rate' => 60_000]);

    $checkin  = Carbon::today()->addDay();
    $checkout = Carbon::today()->addDays(3); // 2 nights

    Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Reservations\Index::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Chi Guest')
        ->set('reservePhone', '08012345678')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', $checkin->toDateString())
        ->set('reserveCheckout', $checkout->toDateString())
        ->set('reserveAdults', 2)
        ->set('reserveBreakfastIncluded', true)
        ->set('reservePaymentStatus', 'confirmed')
        ->call('submitReserve')
        ->assertHasNoErrors();

    $booking = \App\Models\Booking::query()->first();
    expect($booking->breakfast_included)->toBeTrue();
    expect((int) $booking->room_total)->toBe(120_000); // 2 × 60k
});

it('applies an unrestricted auto-apply discount (no channel filter at all)', function (): void {
    Discount::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Everyone 5%',
        'code' => null,
        'type' => DiscountType::Percent,
        'value' => 5,
        'channels' => null,
        'active' => true,
    ]);

    $checkin  = Carbon::today()->addDay();
    $checkout = Carbon::today()->addDays(3);

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Chi Guest')
        ->set('reservePhone', '08012345678')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', $checkin->toDateString())
        ->set('reserveCheckout', $checkout->toDateString())
        ->set('reserveAdults', 2)
        ->set('reservePaymentStatus', 'confirmed')
        ->call('submitReserve')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect((int) $booking->room_total)->toBe(95_000); // 5% off ₦100k
});
