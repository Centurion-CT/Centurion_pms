<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'checkin-prompt']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 4,
        'capacity_children' => 2,
        'base_rate' => 50_000,
        'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->operator, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('surfaces the "Check in now?" prompt when checkin is today', function (): void {
    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Same-day Guest')
        ->set('reservePhone', '08012345678')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDay()->toDateString())
        ->set('reserveAdults', 2)
        ->set('reservePaymentStatus', 'confirmed')
        ->call('submitReserve')
        ->assertHasNoErrors()
        ->assertSet('postCreateShowCheckInPrompt', true)
        ->assertSet('postCreateGuestName', 'Same-day Guest')
        ->assertDispatched('checkin-prompt-open');

    // Booking exists but is NOT redirected yet — the operator has to
    // interact with the prompt first.
    $booking = Booking::query()->first();
    expect($booking)->not->toBeNull();
    expect($booking->booking_status)->toBe(BookingStatus::Confirmed);
});

it('confirming the prompt flips the booking to CheckedIn and redirects', function (): void {
    $component = Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Confirm Guest')
        ->set('reservePhone', '08012340001')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDay()->toDateString())
        ->set('reserveAdults', 1)
        ->set('reservePaymentStatus', 'confirmed')
        ->call('submitReserve')
        ->call('confirmPostCreateCheckIn');

    $booking = Booking::query()->first();
    expect($booking->booking_status)->toBe(BookingStatus::CheckedIn);
    $component->assertRedirect(route('tenant.reservations.show', $booking->booking_ref));
});

it('skipping the prompt leaves the booking untouched and redirects', function (): void {
    $component = Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Skip Guest')
        ->set('reservePhone', '08012340002')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDay()->toDateString())
        ->set('reserveAdults', 1)
        ->set('reservePaymentStatus', 'confirmed')
        ->call('submitReserve')
        ->call('skipPostCreateCheckIn');

    $booking = Booking::query()->first();
    expect($booking->booking_status)->toBe(BookingStatus::Confirmed); // not touched
    $component->assertRedirect(route('tenant.reservations.show', $booking->booking_ref));
});

it('skips the prompt entirely when the checkin is not today', function (): void {
    $component = Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Future Guest')
        ->set('reservePhone', '08012340003')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDays(2)->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(4)->toDateString())
        ->set('reserveAdults', 1)
        ->set('reservePaymentStatus', 'confirmed')
        ->call('submitReserve');

    $component
        ->assertSet('postCreateShowCheckInPrompt', false)
        ->assertNotDispatched('checkin-prompt-open');

    $booking = Booking::query()->first();
    $component->assertRedirect(route('tenant.reservations.show', $booking->booking_ref));
});

it('skips the prompt for a Pending same-day booking (nothing to check in yet)', function (): void {
    $component = Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Pending Guest')
        ->set('reservePhone', '08012340004')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDay()->toDateString())
        ->set('reserveAdults', 1)
        ->set('reservePaymentStatus', 'pending')
        ->call('submitReserve');

    $component->assertSet('postCreateShowCheckInPrompt', false);

    $booking = Booking::query()->first();
    expect($booking->booking_status)->toBe(BookingStatus::Pending);
    $component->assertRedirect(route('tenant.reservations.show', $booking->booking_ref));
});
