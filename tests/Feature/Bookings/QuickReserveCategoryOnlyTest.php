<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Bookings\Index as BookingsIndex;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Quick-reserve modal (the "Reserve a room" sheet shared across the
 * Bookings + Reservations + Calendar pages via HasQuickReserveForm)
 * gains the same category-only toggle as the manual booking form.
 * When ON, the reservation lands with room_id=NULL + room_category_id
 * set; front desk assigns a specific room via AssignRoomToBooking
 * at check-in.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'quick-cat']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '102']);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('creates an unassigned booking on the quick-reserve category-only path', function (): void {
    $ci = Carbon::tomorrow();
    $co = Carbon::tomorrow()->addDays(2);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveCategoryOnly', true)
        ->set('reserveCategoryId', $this->category->id)
        ->set('reserveGuestName', 'Ada QuickCat')
        ->set('reservePhone', '+2348011119999')
        ->set('reserveAdults', 1)
        ->set('reserveCheckin', $ci->toDateString())
        ->set('reserveCheckout', $co->toDateString())
        ->set('reservePaymentStatus', 'pending')    // defer payment
        ->call('submitReserve');

    $booking = Booking::query()->latest('id')->first();
    expect($booking)->not->toBeNull();
    expect($booking->room_id)->toBeNull();
    expect($booking->room_category_id)->toBe($this->category->id);
    expect($booking->guest_name)->toBe('Ada QuickCat');
    expect($booking->booking_status)->toEqual(BookingStatus::Pending);
    expect($booking->payment_status)->toEqual(PaymentStatus::Pending);
    expect((int) $booking->total_amount)->toBe(80_000);   // 2 nights × ₦40k
});

it('requires reserveCategoryId when reserveCategoryOnly is on', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveCategoryOnly', true)
        // reserveCategoryId intentionally left null
        ->set('reserveGuestName', 'X')
        ->set('reservePhone', '+2348011113333')
        ->set('reserveAdults', 1)
        ->set('reserveCheckin', Carbon::tomorrow()->toDateString())
        ->set('reserveCheckout', Carbon::tomorrow()->addDay()->toDateString())
        ->call('submitReserve')
        ->assertHasErrors(['reserveCategoryId']);
});

it('does NOT require reserveRoomId when reserveCategoryOnly is on', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveCategoryOnly', true)
        ->set('reserveCategoryId', $this->category->id)
        ->set('reserveGuestName', 'Y')
        ->set('reservePhone', '+2348011114444')
        ->set('reserveAdults', 1)
        ->set('reserveCheckin', Carbon::tomorrow()->toDateString())
        ->set('reserveCheckout', Carbon::tomorrow()->addDay()->toDateString())
        ->call('submitReserve')
        ->assertHasNoErrors(['reserveRoomId']);
});

it('flipping the toggle clears the stale room pick but preserves the payment settlement', function (): void {
    $c = Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveRoomId', $this->roomA->id)
        ->set('reservePaymentStatus', 'confirmed')
        ->set('reservePaymentAmount', 15_000)
        ->set('reserveCategoryOnly', true);

    // Room clears; payment status + amount ride through so the
    // guest can still pay a deposit or the full stay on the
    // category-only path.
    expect($c->get('reserveRoomId'))->toBeNull();
    expect($c->get('reservePaymentStatus'))->toBe('confirmed');
    expect($c->get('reservePaymentAmount'))->toBe(15_000);
});

it('collects a full payment at reservation time on the category-only path', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveCategoryOnly', true)
        ->set('reserveCategoryId', $this->category->id)
        ->set('reserveGuestName', 'Ada Full')
        ->set('reservePhone', '+2348011118887')
        ->set('reserveAdults', 1)
        ->set('reserveCheckin', Carbon::tomorrow()->toDateString())
        ->set('reserveCheckout', Carbon::tomorrow()->addDay()->toDateString())
        ->set('reservePaymentStatus', 'confirmed')
        ->set('reservePaymentMethod', 'cash')
        ->call('submitReserve');

    $booking = Booking::query()->latest('id')->first();
    expect($booking->room_id)->toBeNull();
    expect($booking->booking_status)->toEqual(\App\Enums\BookingStatus::Confirmed);
    expect($booking->payment_status)->toEqual(\App\Enums\PaymentStatus::Succeeded);
    expect((int) $booking->balance_due)->toBe(0);

    $payment = \App\Models\Payment::query()->where('booking_id', $booking->id)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect((int) $payment->amount)->toBe(40_000);   // 1 night × ₦40k
    expect($payment->method)->toBe('cash');
});

it('collects a partial deposit at reservation time and lands OnHold with balance owed', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveCategoryOnly', true)
        ->set('reserveCategoryId', $this->category->id)
        ->set('reserveGuestName', 'Ada Deposit')
        ->set('reservePhone', '+2348011118886')
        ->set('reserveAdults', 1)
        ->set('reserveCheckin', Carbon::tomorrow()->toDateString())
        ->set('reserveCheckout', Carbon::tomorrow()->addDay()->toDateString())
        ->set('reservePaymentStatus', 'on_hold')
        ->set('reservePaymentMethod', 'transfer')
        ->set('reservePaymentAmount', 15_000)
        ->call('submitReserve');

    $booking = Booking::query()->latest('id')->first();
    expect($booking->booking_status)->toEqual(\App\Enums\BookingStatus::OnHold);
    expect((int) $booking->balance_due)->toBe(25_000);
    expect((int) $booking->deposit_amount)->toBe(15_000);

    $payment = \App\Models\Payment::query()->where('booking_id', $booking->id)->latest('id')->first();
    expect((int) $payment->amount)->toBe(15_000);
    expect($payment->method)->toBe('transfer');
});

it('rejects the submission when every unit in the category is booked for the range', function (): void {
    // Block both rooms in the category for the requested night so
    // categoryFreeUnits() returns 0 and CreateCategoryReservation
    // refuses via RangeNotAvailableException.
    $night = Carbon::tomorrow();
    foreach ([$this->roomA, $this->roomB] as $r) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $r->id,
            'room_category_id' => $this->category->id,
            'date' => $night->format('Y-m-d H:i:s'),
            'type' => \App\Enums\AvailabilityBlockType::Booked->value,
            'booking_id' => null,
        ]);
    }

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveCategoryOnly', true)
        ->set('reserveCategoryId', $this->category->id)
        ->set('reserveGuestName', 'Full')
        ->set('reservePhone', '+2348011115555')
        ->set('reserveAdults', 1)
        ->set('reserveCheckin', $night->toDateString())
        ->set('reserveCheckout', $night->copy()->addDay()->toDateString())
        ->call('submitReserve')
        ->assertHasErrors(['reserveCheckin']);
});
