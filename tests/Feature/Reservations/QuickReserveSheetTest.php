<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'quick-reserve']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    // Pin capacity so the test isn't flaky (RoomCategoryFactory picks
    // capacity_adults randomly between 1-4).
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

it('renders the searchable room picker in the reserve sheet', function (): void {
    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->assertSeeHtml('data-testid="reserve-room"')
        ->assertSeeHtml('data-testid="reserve-room-trigger"')
        ->assertSeeHtml('data-testid="reserve-room-search"')
        ->assertSeeHtml('data-testid="reserve-room-option-'.$this->room->id.'"');
});

it('submits a quick reservation and lands a Confirmed WalkIn booking', function (): void {
    $checkin = Carbon::today()->addDay();
    $checkout = Carbon::today()->addDays(3);

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Ada Okoye')
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
    expect($booking->guest_name)->toBe('Ada Okoye');
    expect($booking->room_id)->toBe($this->room->id);
    expect($booking->booking_status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Succeeded);
    expect($booking->source)->toBe(BookingSource::WalkIn);
});

it('rejects checkout not after checkin', function (): void {
    $day = Carbon::today()->addDay();

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'X')
        ->set('reservePhone', '080')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', $day->toDateString())
        ->set('reserveCheckout', $day->toDateString())
        ->call('submitReserve')
        ->assertHasErrors('reserveCheckout');

    expect(Booking::query()->count())->toBe(0);
});

it('deposit-only records the actual amount and computes outstanding balance', function (): void {
    // 2-night stay at ₦50,000/night → total ₦100,000. Operator collects
    // a ₦30,000 deposit at booking time; ₦70,000 should be owed before
    // check-in and the Payment ledger row should show ₦30,000 (not the
    // full total, which was the pre-fix behaviour).
    $checkin = Carbon::today()->addDays(2);
    $checkout = Carbon::today()->addDays(4);

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Chidi Nwosu')
        ->set('reservePhone', '08099887766')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', $checkin->toDateString())
        ->set('reserveCheckout', $checkout->toDateString())
        ->set('reservePaymentStatus', 'on_hold')
        ->set('reservePaymentAmount', 30_000)
        ->call('submitReserve')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect($booking->booking_status)->toBe(BookingStatus::OnHold);
    expect((int) $booking->total_amount)->toBe(100_000);
    expect((int) $booking->deposit_amount)->toBe(30_000);
    expect((int) $booking->balance_due)->toBe(70_000);

    $payment = Payment::query()->where('booking_id', $booking->id)->first();
    expect($payment)->not->toBeNull();
    expect((int) $payment->amount)->toBe(30_000);
});

it('deposit ≥ total auto-confirms the booking and credits surplus to wallet', function (): void {
    // Category from beforeEach has base_rate 50k. Force a Percent
    // deposit policy so the min-deposit floor exists AND the overpay
    // path exercises the wallet credit.
    $this->category->update(['deposit_type' => 'percent', 'deposit_value' => 30]);

    $customer = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name'      => 'Ada Wallet',
        'phone'     => '08055554444',
    ]);
    $walletBefore = (int) $customer->walletBalance();

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reservePhone', '08055554444')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDays(1)->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(2)->toDateString())
        ->set('reservePaymentStatus', 'on_hold')
        ->set('reservePaymentAmount', 55_000) // ₦5k overpayment on a ₦50k 1-night
        ->call('submitReserve')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect((int) $booking->total_amount)->toBe(50_000);
    expect((int) $booking->balance_due)->toBe(0);
    // Deposit "path" but the collected amount covers the total, so
    // the booking upgrades to Confirmed instead of sitting OnHold.
    expect($booking->booking_status)->toBe(BookingStatus::Confirmed);

    $payment = Payment::query()->where('booking_id', $booking->id)->first();
    expect((int) $payment->amount)->toBe(50_000);
    expect($customer->fresh()->walletBalance())->toBe($walletBefore + 5_000);
});

it('persists the selected payment method on the Payment row', function (): void {
    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Transfer Guest')
        ->set('reservePhone', '08000000003')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDays(1)->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(2)->toDateString())
        ->set('reservePaymentStatus', 'confirmed')
        ->set('reservePaymentMethod', 'transfer')
        ->call('submitReserve')
        ->assertHasNoErrors();

    $payment = Payment::query()->first();
    expect($payment)->not->toBeNull();
    expect($payment->method)->toBe('transfer');
});

it('rejects a deposit below the room category minimum', function (): void {
    // 30% deposit policy on the beforeEach category → 30% of a
    // ₦100,000 2-night stay is ₦30,000. Anything less should fail.
    $this->category->update(['deposit_type' => 'percent', 'deposit_value' => 30]);

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Under Deposit')
        ->set('reservePhone', '08000000002')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDays(1)->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(3)->toDateString())
        ->set('reservePaymentStatus', 'on_hold')
        ->set('reservePaymentAmount', 5_000) // < ₦30,000 floor
        ->call('submitReserve')
        ->assertHasErrors('reservePaymentAmount');

    expect(Booking::query()->count())->toBe(0);
});

it('leaves booking as Pending when payment status is pending', function (): void {
    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveGuestName', 'Later Payer')
        ->set('reservePhone', '08000000000')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDays(5)->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(7)->toDateString())
        ->set('reservePaymentStatus', 'pending')
        ->call('submitReserve')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect($booking->booking_status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::Pending);
});
