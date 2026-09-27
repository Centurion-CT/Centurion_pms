<?php

declare(strict_types=1);

use App\Actions\Bookings\ApplyPostCheckinDiscount;
use App\Enums\BookingStatus;
use App\Enums\ManualDiscountType;
use App\Enums\OperatorRole;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'post-discount']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'name' => 'Ada Manager',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('reduces total + balance and stacks into manual_discount_amount (percent mode)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 100_000,
        'balance_due' => 40_000,
        'manual_discount_amount' => 0,
    ]);

    app(ApplyPostCheckinDiscount::class)->handle(
        booking: $b,
        operator: $this->operator,
        mode: ManualDiscountType::Percent,
        percent: 15.0,
    );

    $b->refresh();
    // 100k × 15% = 15k off → total 85k, balance 25k, stacked amount 15k.
    expect($b->total_amount)->toBe(85_000);
    expect($b->balance_due)->toBe(25_000);
    expect($b->manual_discount_amount)->toBe(15_000);
});

it('applies a flat ₦ discount and caps at the total', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 50_000, 'balance_due' => 0,
    ]);

    app(ApplyPostCheckinDiscount::class)->handle(
        booking: $b,
        operator: $this->operator,
        mode: ManualDiscountType::Fixed,
        fixedAmount: 999_999,
    );

    $b->refresh();
    expect($b->total_amount)->toBe(0);
    expect($b->manual_discount_amount)->toBe(50_000);
});

it('logs a post_checkin_discount_applied activity with operator + reason', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 80_000, 'balance_due' => 30_000,
    ]);

    app(ApplyPostCheckinDiscount::class)->handle(
        booking: $b,
        operator: $this->operator,
        mode: ManualDiscountType::Fixed,
        fixedAmount: 20_000,
        reason: 'AC issue on night 1',
    );

    $activity = Activity::query()
        ->where('event', 'post_checkin_discount_applied')
        ->where('subject_id', $b->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->causer_id)->toBe($this->operator->id);
    $props = $activity->properties;
    expect($props['amount'])->toBe(20_000);
    expect($props['mode'])->toBe('fixed');
    expect($props['reason'])->toBe('AC issue on night 1');
    expect($props['total_before'])->toBe(80_000);
    expect($props['total_after'])->toBe(60_000);
});

it('refuses a zero-value discount', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 50_000,
    ]);

    expect(fn () => app(ApplyPostCheckinDiscount::class)->handle(
        booking: $b,
        operator: $this->operator,
        mode: ManualDiscountType::Percent,
        percent: 0.0,
    ))->toThrow(InvalidArgumentException::class);
});

it('never drives balance_due below 0 when guest already paid in full', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 100_000, 'balance_due' => 0,  // fully paid
    ]);

    app(ApplyPostCheckinDiscount::class)->handle(
        booking: $b,
        operator: $this->operator,
        mode: ManualDiscountType::Fixed,
        fixedAmount: 20_000,
    );

    $b->refresh();
    expect($b->total_amount)->toBe(80_000);
    expect($b->balance_due)->toBe(0);  // stays 0, surplus lives on the wallet
});
