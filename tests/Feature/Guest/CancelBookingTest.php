<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBooking;
use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->action = app(CancelBooking::class);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('cancels with no deposit forfeit when checkin is more than 7 days out', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'checkin_date' => '2030-08-15',
        'checkout_date' => '2030-08-17',
        'deposit_amount' => 30_000,
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->createMany([
            ['date' => '2030-08-15', 'booking_id' => $booking->id],
            ['date' => '2030-08-16', 'booking_id' => $booking->id],
        ]);

    $result = $this->action->handle($booking);

    expect($result->depositForfeit)->toBeFalse();
    expect($result->daysUntilCheckin)->toBe(14);
    expect($result->booking->booking_status)->toBe(BookingStatus::Cancelled);
    expect(AvailabilityBlock::where('booking_id', $booking->id)->count())->toBe(0);
});

it('forfeits deposit when checkin is within 7 days', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'checkin_date' => '2030-08-05',
        'checkout_date' => '2030-08-07',
        'deposit_amount' => 30_000,
    ]);

    $result = $this->action->handle($booking);

    expect($result->depositForfeit)->toBeTrue();
    expect($result->daysUntilCheckin)->toBe(4);
});

it('does not forfeit when deposit is zero, even inside the 7-day window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-08-05',
        'checkout_date' => '2030-08-07',
        'deposit_amount' => 0,
    ]);

    $result = $this->action->handle($booking);

    expect($result->depositForfeit)->toBeFalse();
});

it('preserves operator-Blocked rows on the same dates (only deletes Booked)', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-08-15',
        'checkout_date' => '2030-08-17',
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create(['date' => '2030-08-15', 'type' => AvailabilityBlockType::Blocked]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2030-08-16', 'booking_id' => $booking->id]);

    $this->action->handle($booking);

    expect(AvailabilityBlock::count())->toBe(1);
    expect(AvailabilityBlock::first()->type)->toBe(AvailabilityBlockType::Blocked);
});

it('throws when the booking is already Cancelled', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create();

    expect(fn () => $this->action->handle($booking))->toThrow(RuntimeException::class);
});

it('throws when the booking is CheckedOut', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'booking_status' => BookingStatus::CheckedOut,
    ]);

    expect(fn () => $this->action->handle($booking))->toThrow(RuntimeException::class);
});
