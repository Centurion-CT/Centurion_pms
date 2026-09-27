<?php

declare(strict_types=1);

use App\Actions\Bookings\RecordManualPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'record-pay']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create(['name' => 'Op Cash']);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('inserts a Succeeded Payment row for the booking total', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 150_000,
    ]);

    $payment = app(RecordManualPayment::class)->handle($booking, $this->operator);

    expect($payment)->not->toBeNull();
    expect($payment->amount)->toBe(150_000);
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
    expect($payment->type)->toBe(PaymentType::Full);
    expect($payment->paid_at)->not->toBeNull();
    expect($payment->method)->toBe('cash');
    expect(str_starts_with($payment->paystack_reference, 'manual-'))->toBeTrue();
});

it('is idempotent — a second call on the same booking is a no-op', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 100_000,
    ]);

    $first = app(RecordManualPayment::class)->handle($booking, $this->operator);
    $second = app(RecordManualPayment::class)->handle($booking, $this->operator);

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

it('records only the outstanding balance when a deposit already exists', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 200_000,
    ]);
    // Prior Paystack deposit of 50k.
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $booking->id,
        'amount'     => 50_000,
        'type'       => PaymentType::Deposit,
        'status'     => PaymentStatus::Succeeded,
        'paid_at'    => now()->subDay(),
    ]);

    $balance = app(RecordManualPayment::class)->handle($booking, $this->operator);

    expect($balance)->not->toBeNull();
    expect($balance->amount)->toBe(150_000);
    expect($balance->type)->toBe(PaymentType::Balance);
    expect(Payment::query()->where('booking_id', $booking->id)->where('status', PaymentStatus::Succeeded)->sum('amount'))
        ->toBe(200_000);
});

it('returns null and inserts nothing when the booking total is zero', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 0,
    ]);

    $payment = app(RecordManualPayment::class)->handle($booking, $this->operator);

    expect($payment)->toBeNull();
    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(0);
});

it('records an activity log line when an operator is present', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 75_000,
    ]);

    app(RecordManualPayment::class)->handle($booking, $this->operator);

    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', Booking::class)
        ->where('subject_id', $booking->id)
        ->where('event', 'manual_payment_recorded')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->description)->toContain('Op Cash');
});
