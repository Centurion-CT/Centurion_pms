<?php

declare(strict_types=1);

use App\Actions\Bookings\SettleCharges;
use App\Actions\Bookings\SettleChargesWalletFirst;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;

/**
 * Regression: "Settle everything" was skipping booking.balance_due
 * entirely — the action only iterated unsettled BookingCharges, so a
 * booking whose outstanding was room revenue (not extras) had a
 * button that reported success and left the guest stuck at the same
 * outstanding tile with checkout still blocked.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'settle-balance']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create([
            'status' => RoomStatus::Active, 'room_number' => '210',
        ]);
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    // Booking with an unpaid room balance + zero unsettled charges.
    // This is the exact shape Suliat's booking was in when the bug fired.
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 50_000,
        'room_total' => 50_000,
        'balance_due' => 50_000,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('clears balance_due and writes a Balance-type Payment when there are no extra charges', function (): void {
    $count = app(SettleCharges::class)->settleAll($this->booking, $this->operator, 'cash');

    expect($count)->toBe(1);
    expect($this->booking->fresh()->balance_due)->toBe(0);
    expect($this->booking->fresh()->outstandingTotal())->toBe(0);

    $payment = Payment::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', PaymentType::Balance->value)
        ->first();
    expect($payment)->not->toBeNull();
    expect($payment->amount)->toBe(50_000);
    expect($payment->method)->toBe('cash');
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
});

it('drains both balance_due and unsettled charges in one Settle Everything call', function (): void {
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'F&B · Rooftop Bar',
        'amount' => 15_000,
        'settled' => false,
    ]);

    $count = app(SettleCharges::class)->settleAll($this->booking, $this->operator, 'transfer');

    expect($count)->toBe(2);  // balance + 1 charge
    expect($this->booking->fresh()->balance_due)->toBe(0);
    expect($this->booking->fresh()->outstandingTotal())->toBe(0);
    expect(Payment::query()->where('booking_id', $this->booking->id)->where('type', PaymentType::Balance->value)->sum('amount'))->toBe(50_000);
    expect(Payment::query()->where('booking_id', $this->booking->id)->where('type', PaymentType::Extra->value)->sum('amount'))->toBe(15_000);
});

it('is a no-op when there is nothing outstanding', function (): void {
    $this->booking->forceFill(['balance_due' => 0])->save();

    $count = app(SettleCharges::class)->settleAll($this->booking, $this->operator, 'cash');

    expect($count)->toBe(0);
    expect(Payment::query()->where('booking_id', $this->booking->id)->count())->toBe(0);
});

it('wallet-first drains wallet against balance_due before touching extra charges', function (): void {
    $customer = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Suliat Amusa',
        'phone' => '08099998888',
    ]);
    $this->booking->forceFill(['customer_id' => $customer->id])->save();

    // Fund wallet with ₦30k, not enough to cover the ₦50k balance.
    // Wallet should chip at balance_due first, then fallback covers
    // the ₦20k remainder + any additional charges.
    app(WalletService::class)->topup(
        customer: $customer,
        amount: 30_000,
        description: 'Wallet top-up',
        paymentMethod: 'cash',
        causer: $this->operator,
    );

    // Add an extra charge to make the sweep interesting.
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Laundry',
        'amount' => 5_000,
        'settled' => false,
    ]);

    $result = app(SettleChargesWalletFirst::class)->handle($this->booking, $this->operator, 'cash');

    expect($result['wallet'])->toBe(30_000);
    expect($this->booking->fresh()->balance_due)->toBe(0);
    expect($this->booking->fresh()->outstandingTotal())->toBe(0);

    // Wallet spends are ledgered as CustomerWalletTransactions, not
    // Payment rows (that's the existing convention). The remaining
    // 25k (20k balance shortfall + 5k charge) lands on /payments.
    $totalPaid = Payment::query()->where('booking_id', $this->booking->id)->sum('amount');
    expect($totalPaid)->toBe(25_000);
    // Wallet balance is drained.
    expect((int) $customer->fresh()->walletBalance())->toBe(0);
});
