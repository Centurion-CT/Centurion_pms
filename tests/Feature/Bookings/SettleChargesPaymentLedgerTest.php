<?php

declare(strict_types=1);

use App\Actions\Bookings\SettleCharges;
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

/**
 * SettleCharges must write a Payment row for every settled charge so
 * the booking's payments ledger + the Payments dashboard + Finance's
 * "Collected" all reflect the cash that came in. Prior to this the
 * "Mark paid" click on a charge just flipped settled=true and the
 * money was invisible on the payment ledger.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'settle-ledger']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create([
            'status' => RoomStatus::Active, 'room_number' => '501',
        ]);
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
        'nights' => 1,
        'total_amount' => 50_000,
        'room_total' => 50_000,
        'balance_due' => 0,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('settleOne writes a Payment row that mirrors the charge amount + method', function (): void {
    $charge = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Stay extended · 1 extra night',
        'amount' => 50_000,
        'settled' => false,
    ]);

    app(SettleCharges::class)->settleOne($charge, $this->operator, 'transfer');

    $payment = Payment::query()->where('booking_id', $this->booking->id)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect((int) $payment->amount)->toBe(50_000);
    expect($payment->method)->toBe('transfer');
    expect($payment->type)->toEqual(PaymentType::Extra);
    expect($payment->status)->toEqual(PaymentStatus::Succeeded);
    expect($payment->collected_by_operator_id)->toBe($this->operator->id);
});

it('settleAll writes one Payment row per unsettled charge', function (): void {
    // Two independent unsettled charges - each should land on the
    // ledger as its own Payment row, not one aggregate.
    $c1 = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Extension · 1 extra night', 'amount' => 50_000, 'settled' => false,
    ]);
    $c2 = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::RoomService->value,
        'description' => 'Dinner + drinks', 'amount' => 12_000, 'settled' => false,
    ]);

    app(SettleCharges::class)->settleAll($this->booking, $this->operator, 'cash');

    $payments = Payment::query()->where('booking_id', $this->booking->id)->orderBy('id')->get();
    expect($payments)->toHaveCount(2);
    expect($payments->pluck('amount')->map(fn ($a) => (int) $a)->all())
        ->toBe([50_000, 12_000]);
    foreach ($payments as $p) {
        expect($p->method)->toBe('cash');
        expect($p->type)->toEqual(PaymentType::Extra);
        expect($p->status)->toEqual(PaymentStatus::Succeeded);
    }
});

it('settleOne is a no-op on an already-settled charge (no duplicate Payment)', function (): void {
    $charge = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::RoomService->value,
        'description' => 'Laundry',
        'amount' => 3_500,
        'settled' => true,
        'settled_at' => Carbon::now(),
        'settled_method' => 'cash',
    ]);

    app(SettleCharges::class)->settleOne($charge, $this->operator, 'transfer');

    expect(Payment::query()->where('booking_id', $this->booking->id)->count())->toBe(0);
});

it('truncates long charge descriptions so reference_note stays inside varchar(120)', function (): void {
    // Regression: on prod a settlement failed with "value too long
    // for type character varying(120)" when the extension charge
    // carried an extended description like:
    //   "Stay extended to Sep 10, 2026 · 2 extra nights · ₦60,000
    //    credit for overstay already paid · ₦10,000 discount applied"
    // The "Settled: " prefix + Str::limit(desc, 120) landed at ~129
    // chars, blowing past the column limit.
    $longDesc = str_repeat('very long description ', 20);   // ~440 chars
    $c = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => $longDesc,
        'amount' => 50_000, 'settled' => false,
    ]);

    app(SettleCharges::class)->settleOne($c, $this->operator, 'cash');

    $payment = Payment::query()->where('booking_id', $this->booking->id)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect(mb_strlen((string) $payment->reference_note))->toBeLessThanOrEqual(120);
});

it('multiple extensions each get their own Payment row on settle', function (): void {
    // Real-world scenario the user hit: extend guest, mark paid,
    // extend again, mark paid. Each extension's settlement should
    // land as a distinct Payment on the ledger so the operator can
    // see them separately on the payments page.
    $c1 = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Stay extended · 1 extra night', 'amount' => 50_000, 'settled' => false,
    ]);
    app(SettleCharges::class)->settleOne($c1, $this->operator, 'cash');

    $c2 = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::Extension->value,
        'description' => 'Stay extended · 1 extra night', 'amount' => 50_000, 'settled' => false,
    ]);
    app(SettleCharges::class)->settleOne($c2, $this->operator, 'transfer');

    $payments = Payment::query()->where('booking_id', $this->booking->id)->orderBy('id')->get();
    expect($payments)->toHaveCount(2);
    expect($payments->pluck('method')->all())->toBe(['cash', 'transfer']);
});
