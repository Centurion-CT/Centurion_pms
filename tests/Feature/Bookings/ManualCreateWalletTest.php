<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Bookings\ManualCreate;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'manual-wallet']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 4, 'capacity_children' => 2, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('picked customer with wallet balance surfaces the wallet balance', function (): void {
    $customer = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Ada Okoye', 'email' => 'ada@example.test', 'phone' => '08012345678',
    ]);
    app(WalletService::class)->topup($customer, 500_000, 'seed');

    Livewire::test(ManualCreate::class)
        ->call('pickCustomer', $customer->id)
        ->assertSet('selectedCustomerId', $customer->id)
        ->assertSet('guest_name', 'Ada Okoye')
        ->assertSet('guest_phone', '08012345678');

    // pickedCustomerWalletBalance is a computed; we exercise it via
    // the view render + assertion — value comes back as expected.
    $c = Livewire::test(ManualCreate::class)->call('pickCustomer', $customer->id);
    expect($c->instance()->pickedCustomerWalletBalance)->toBe(500_000);
});

it('paying from wallet books Confirmed when balance covers the estimate', function (): void {
    $customer = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Musa I.', 'email' => 'musa@example.test', 'phone' => '08099999999',
    ]);
    app(WalletService::class)->topup($customer, 500_000, 'seed');    // ₦5,000 wallet
    // 2 nights × ₦500 = ₦1,000 estimate — wallet covers.

    Livewire::test(ManualCreate::class)
        ->call('pickCustomer', $customer->id)
        ->set('roomId', $this->room->id)
        ->set('checkin', Carbon::today()->addDay()->toDateString())
        ->set('checkout', Carbon::today()->addDays(3)->toDateString())
        ->set('adults', 1)
        ->set('payment_settlement', 'wallet')
        ->call('save')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect($booking)->not->toBeNull();
    expect($booking->booking_status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Succeeded);
    expect($customer->fresh()->walletBalance())->toBeLessThan(500_000);
});

it('deposit-only records deposit and leaves outstanding balance on the booking', function (): void {
    // 30% category deposit policy on a 2-night stay at ₦50k/night
    // → total ₦100k, min deposit ₦30k. Operator collects ₦40k.
    $this->category->update(['deposit_type' => 'percent', 'deposit_value' => 30]);

    Livewire::test(ManualCreate::class)
        ->set('guest_name', 'Deposit Guest')
        ->set('guest_email', 'dep@example.test')
        ->set('guest_phone', '08011112222')
        ->set('roomId', $this->room->id)
        ->set('checkin', Carbon::today()->addDay()->toDateString())
        ->set('checkout', Carbon::today()->addDays(3)->toDateString())
        ->set('adults', 1)
        ->set('payment_settlement', 'on_hold')
        ->set('payment_amount', 40_000)
        ->set('payment_method', 'transfer')
        ->call('save')
        ->assertHasNoErrors();

    $booking = Booking::query()->first();
    expect($booking->booking_status)->toBe(BookingStatus::OnHold);
    expect((int) $booking->total_amount)->toBe(100_000);
    expect((int) $booking->deposit_amount)->toBe(40_000);
    expect((int) $booking->balance_due)->toBe(60_000);

    $payment = Payment::query()->first();
    expect((int) $payment->amount)->toBe(40_000);
    expect($payment->method)->toBe('transfer');
});

it('deposit below the category minimum is rejected', function (): void {
    $this->category->update(['deposit_type' => 'percent', 'deposit_value' => 30]);

    Livewire::test(ManualCreate::class)
        ->set('guest_name', 'Under Deposit')
        ->set('guest_email', 'under@example.test')
        ->set('guest_phone', '08011113333')
        ->set('roomId', $this->room->id)
        ->set('checkin', Carbon::today()->addDay()->toDateString())
        ->set('checkout', Carbon::today()->addDays(3)->toDateString())
        ->set('adults', 1)
        ->set('payment_settlement', 'on_hold')
        ->set('payment_amount', 5_000) // way under the ₦30k floor
        ->set('payment_method', 'cash')
        ->call('save')
        ->assertHasErrors('payment_amount');

    expect(Booking::query()->count())->toBe(0);
});

it('refuses wallet payment when no customer was picked', function (): void {
    Livewire::test(ManualCreate::class)
        ->set('guest_name', 'Random Walk-in')
        ->set('guest_email', 'x@x.test')
        ->set('guest_phone', '08000000000')
        ->set('roomId', $this->room->id)
        ->set('checkin', Carbon::today()->addDay()->toDateString())
        ->set('checkout', Carbon::today()->addDays(2)->toDateString())
        ->set('adults', 1)
        ->set('payment_settlement', 'wallet')
        ->call('save')
        ->assertHasErrors('payment_settlement');

    expect(Booking::query()->count())->toBe(0);
});
