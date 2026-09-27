<?php

declare(strict_types=1);

use App\Actions\Bookings\MarkCheckedOut;
use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Bookings\EarlyCheckoutQuote;
use Illuminate\Support\Carbon;

/**
 * Guest paid for extra nights, then left early — where does the
 * money go? Prior to this feature: silently kept as revenue with
 * no ledger trace. Now the operator picks 'keep', 'wallet', or
 * 'cash' and MarkCheckedOut honours it + logs an activity entry.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'early-checkout']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->customer = Customer::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Early Leaver',
        'phone' => '+2348011119999',
        'lifecycle' => CustomerLifecycle::Active,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function seedBooking(): Booking
{
    // 3 nights, all paid up front. Guest is on Sep 5; booked through
    // Sep 8, so leaving Sep 6 = 2 nights unused × ₦40k = ₦80k unused.
    Carbon::setTestNow(Carbon::parse('2027-09-06 10:00'));
    $b = Booking::factory()->for(test()->tenant)->for(test()->room)->create([
        'customer_id' => test()->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2027-09-05',
        'checkout_date' => '2027-09-08',
        'nights' => 3,
        'room_total' => 120_000,
        'total_amount' => 120_000,
        'balance_due' => 0,
    ]);
    // A single successful payment covering everything up front.
    Payment::query()->create([
        'tenant_id' => $b->tenant_id,
        'booking_id' => $b->id,
        'amount' => 120_000,
        'type' => PaymentType::Balance,
        'method' => 'cash',
        'status' => PaymentStatus::Succeeded,
        'paid_at' => Carbon::parse('2027-09-05 14:00'),
        'paystack_reference' => 'seed-'.uniqid(),
    ]);

    return $b;
}

it('quote detects the unused nights + caps refund at what was actually paid', function (): void {
    $b = seedBooking();

    // Only ₦100k of the ₦120k was actually paid. Unused amount is
    // still ₦80k on paper, but refundable is capped at paid.
    Payment::query()->where('booking_id', $b->id)->delete();
    Payment::query()->create([
        'tenant_id' => $b->tenant_id,
        'booking_id' => $b->id,
        'amount' => 100_000,
        'type' => PaymentType::Balance,
        'method' => 'cash',
        'status' => PaymentStatus::Succeeded,
        'paid_at' => Carbon::now(),
        'paystack_reference' => 'partial-'.uniqid(),
    ]);

    $q = app(EarlyCheckoutQuote::class)->quote($b->fresh());
    expect($q['is_early'])->toBeTrue();
    expect($q['unused_nights'])->toBe(2);
    expect($q['unused_amount'])->toBe(80_000);
    expect($q['refundable_amount'])->toBe(80_000);
});

it('keep mode: no wallet delta, no refund payment, logs decision', function (): void {
    $b = seedBooking();
    $walletBefore = (int) $this->customer->fresh()->walletBalance();

    app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator, 'keep');

    expect((int) $this->customer->fresh()->walletBalance())->toBe($walletBefore);
    // No refund Payment row.
    expect(Payment::query()->where('booking_id', $b->id)->where('amount', '<', 0)->count())->toBe(0);
    // Activity log entry for the decision.
    expect(\App\Models\Activity::query()->where('subject_id', $b->id)->where('event', 'early_checkout_keep')->count())->toBe(1);
});

it('wallet mode: credits the customer wallet + logs the decision', function (): void {
    $b = seedBooking();

    app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator, 'wallet');

    expect((int) $this->customer->fresh()->walletBalance())->toBe(80_000);
    expect(\App\Models\Activity::query()->where('subject_id', $b->id)->where('event', 'early_checkout_wallet')->count())->toBe(1);
});

it('cash mode: writes a negative Refunded Payment row', function (): void {
    $b = seedBooking();

    app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator, 'cash');

    $refund = Payment::query()
        ->where('booking_id', $b->id)
        ->where('amount', '<', 0)
        ->first();
    expect($refund)->not->toBeNull();
    expect((int) $refund->amount)->toBe(-80_000);
    expect($refund->status)->toBe(PaymentStatus::Refunded);
    expect($refund->method)->toBe('cash');
    expect(\App\Models\Activity::query()->where('subject_id', $b->id)->where('event', 'early_checkout_cash')->count())->toBe(1);
});

it('same-day / on-time checkout is NOT flagged as early', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-09-08 10:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2027-09-05',
        'checkout_date' => '2027-09-08',
        'nights' => 3,
        'room_total' => 120_000,
    ]);

    $q = app(EarlyCheckoutQuote::class)->quote($b);
    expect($q['is_early'])->toBeFalse();
    expect($q['unused_nights'])->toBe(0);
});

it('quote applies the property refund_percent policy', function (): void {
    $b = seedBooking();

    // Half-refund policy — unused_amount 80k, refundable = 40k.
    $q = app(EarlyCheckoutQuote::class)->quote($b->fresh(), refundPercent: 50);
    expect($q['unused_amount'])->toBe(80_000);
    expect($q['refundable_amount'])->toBe(40_000);
    expect($q['refund_percent'])->toBe(50);

    // Non-refundable policy — refundable is zero even though the
    // unused amount is still reported (for display).
    $q = app(EarlyCheckoutQuote::class)->quote($b->fresh(), refundPercent: 0);
    expect($q['unused_amount'])->toBe(80_000);
    expect($q['refundable_amount'])->toBe(0);
});

it('MarkCheckedOut respects the property refund_percent when computing wallet credit', function (): void {
    $this->property->update([
        'checkout_policy' => [
            'early_checkout_default_mode' => 'wallet',
            'early_checkout_refund_percent' => 50, // half-refund
        ],
    ]);
    $b = seedBooking();

    app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator, 'wallet');

    // Unused 80k × 50% = 40k credited (not the full 80k).
    expect((int) $this->customer->fresh()->walletBalance())->toBe(40_000);
});

it('trims checkout_date + nights so revenue reports reflect the shortened stay', function (): void {
    $b = seedBooking();
    expect($b->nights)->toBe(3);

    app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator, 'wallet');

    $b->refresh();
    expect($b->checkout_date->toDateString())->toBe(Carbon::today()->toDateString());
    expect($b->nights)->toBe(1); // one full night stayed
});
