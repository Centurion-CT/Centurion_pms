<?php

declare(strict_types=1);

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Cross-tenant backfill: every historical settled non-wallet charge
 * without a matching Payment gets one inserted, sized + methoded
 * exactly like the new SettleCharges::recordPayment() writes.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'backfill-tenant']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create([
            'status' => RoomStatus::Active, 'room_number' => '801',
        ]);
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_ref' => 'KV-BACKFILL01',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'total_amount' => 50_000, 'room_total' => 50_000,
        'balance_due' => 0,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('inserts a Payment for every historical settled charge missing one', function (): void {
    // Two settled charges, no matching Payment rows - both should
    // land as Extra Payments on the ledger.
    $c1 = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Stay extended · 1 extra night',
        'amount' => 50_000,
        'settled' => true, 'settled_at' => Carbon::yesterday()->setTime(10, 0),
        'settled_method' => 'cash',
    ]);
    $c2 = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::RoomService->value,
        'description' => 'Dinner',
        'amount' => 8_000,
        'settled' => true, 'settled_at' => Carbon::yesterday()->setTime(11, 0),
        'settled_method' => 'transfer',
    ]);

    Artisan::call('bookings:backfill-settled-charge-payments', ['--apply' => true]);

    $payments = Payment::query()->where('booking_id', $this->booking->id)->orderBy('id')->get();
    expect($payments)->toHaveCount(2);
    expect($payments->pluck('amount')->map(fn ($a) => (int) $a)->all())->toBe([50_000, 8_000]);
    expect($payments->pluck('method')->all())->toBe(['cash', 'transfer']);
    foreach ($payments as $p) {
        expect($p->type)->toEqual(PaymentType::Extra);
        expect($p->status)->toEqual(PaymentStatus::Succeeded);
    }
});

it('skips charges that already have a matching Payment (idempotent)', function (): void {
    $settledAt = Carbon::yesterday()->setTime(14, 0);
    $c = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Extension night',
        'amount' => 50_000,
        'settled' => true, 'settled_at' => $settledAt,
        'settled_method' => 'cash',
    ]);
    // Existing Payment row within the 60s match window - should be
    // recognised as "already covered" and skipped.
    Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'paystack_reference' => 'pre-existing-'.uniqid(),
        'amount' => 50_000,
        'type' => PaymentType::Extra->value,
        'method' => 'cash',
        'status' => PaymentStatus::Succeeded->value,
        'paid_at' => $settledAt->copy()->addSeconds(3),
        'collected_by_operator_id' => $this->operator->id,
    ]);

    Artisan::call('bookings:backfill-settled-charge-payments', ['--apply' => true]);

    // Still just the one Payment row.
    expect(Payment::query()->where('booking_id', $this->booking->id)->count())->toBe(1);
});

it('never inserts a Payment for a wallet-settled charge (wallet path already wrote one)', function (): void {
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Wallet-settled extension', 'amount' => 40_000,
        'settled' => true, 'settled_at' => Carbon::yesterday(),
        'settled_method' => 'wallet',
    ]);

    Artisan::call('bookings:backfill-settled-charge-payments', ['--apply' => true]);

    expect(Payment::query()->where('booking_id', $this->booking->id)->count())->toBe(0);
});

it('leaves unsettled charges alone', function (): void {
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Unsettled', 'amount' => 25_000,
        'settled' => false,
    ]);

    Artisan::call('bookings:backfill-settled-charge-payments', ['--apply' => true]);

    expect(Payment::query()->where('booking_id', $this->booking->id)->count())->toBe(0);
});

it('dry-run writes nothing', function (): void {
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Extension night', 'amount' => 50_000,
        'settled' => true, 'settled_at' => Carbon::yesterday(),
        'settled_method' => 'cash',
    ]);

    Artisan::call('bookings:backfill-settled-charge-payments');
    $output = Artisan::output();

    expect($output)->toContain('Dry-run');
    expect(Payment::query()->where('booking_id', $this->booking->id)->count())->toBe(0);
});
