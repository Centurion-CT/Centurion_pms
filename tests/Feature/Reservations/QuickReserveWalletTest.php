<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'reserve-wallet']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 4,
        'capacity_children' => 2,
        'base_rate' => 50_000,      // ₦500 per night
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

it('phone lookup autofills guest name + surfaces wallet balance', function (): void {
    $customer = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name'      => 'Ada Okoye',
        'email'     => 'ada@example.test',
        'phone'     => '08012345678',
    ]);
    app(WalletService::class)->topup(
        customer: $customer,
        amount: 500_000,          // ₦5,000 in wallet
        description: 'test seed',
    );

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reservePhone', '08012345678')
        ->assertSet('reserveMatchedCustomerId', $customer->id)
        ->assertSet('reserveGuestName', 'Ada Okoye')
        ->assertSet('reserveWalletBalance', 500_000);
});

it('paying from wallet books Confirmed when balance covers the estimate', function (): void {
    $customer = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name'      => 'Musa I.',
        'email'     => 'musa@example.test',
        'phone'     => '08099999999',
    ]);
    app(WalletService::class)->topup($customer, 500_000, 'seed'); // ₦5,000

    // 2 nights × ₦500 = ₦1,000 — well under wallet.
    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reservePhone', '08099999999')
        ->set('reserveGuestName', 'Musa I.')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDay()->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(3)->toDateString())
        ->set('reservePaymentStatus', 'wallet')
        ->call('submitReserve')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect($booking)->not->toBeNull();
    expect($booking->booking_status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Succeeded);
    expect($customer->fresh()->walletBalance())->toBeLessThan(500_000);
});

it('rejects wallet payment when phone did not match a customer', function (): void {
    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reservePhone', '08000000000')     // no customer with this
        ->set('reserveGuestName', 'Stranger')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDay()->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(2)->toDateString())
        ->set('reservePaymentStatus', 'wallet')
        ->call('submitReserve')
        ->assertHasErrors('reservePaymentStatus');

    expect(Booking::query()->count())->toBe(0);
});

it('openReserveFromCalendar pre-fills the sheet with the selected range', function (): void {
    $day = Carbon::today()->addDays(5);

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveFromCalendar', $day->toDateString(), $day->copy()->addDays(2)->toDateString())
        ->assertSet('reserveSheetOpen', true)
        ->assertSet('reserveCheckin', $day->toDateString())
        // Selected span is INCLUSIVE (3 days) so checkout is +3 (exclusive).
        ->assertSet('reserveCheckout', $day->copy()->addDays(3)->toDateString());
});

it('single-day click sets a 1-night stay', function (): void {
    $day = Carbon::today()->addDays(10);

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveFromCalendar', $day->toDateString(), $day->toDateString())
        ->assertSet('reserveCheckin', $day->toDateString())
        ->assertSet('reserveCheckout', $day->copy()->addDay()->toDateString());
});
