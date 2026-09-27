<?php

declare(strict_types=1);

use App\Actions\Bookings\RequestDateChange;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->action = app(RequestDateChange::class);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('creates a pending change request with normalized dates', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    $req = $this->action->handle(
        booking: $booking,
        requestedCheckin: Carbon::parse('2030-09-10'),
        requestedCheckout: Carbon::parse('2030-09-12'),
        guestNote: '  please move us  ',
    );

    expect($req->status)->toBe(BookingChangeRequest::STATUS_PENDING);
    expect($req->tenant_id)->toBe($this->tenant->id);
    expect($req->booking_id)->toBe($booking->id);
    expect($req->guest_note)->toBe('please move us');
    expect($req->requested_checkin->toDateString())->toBe('2030-09-10');
    expect($req->requested_checkout->toDateString())->toBe('2030-09-12');
});

it('rejects checkout not after checkin', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    expect(fn () => $this->action->handle(
        $booking, Carbon::parse('2030-09-10'), Carbon::parse('2030-09-10'),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects checkin not in the future', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    expect(fn () => $this->action->handle(
        $booking, Carbon::parse('2030-08-01'), Carbon::parse('2030-08-03'),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects when a pending request already exists', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    $this->action->handle($booking, Carbon::parse('2030-09-10'), Carbon::parse('2030-09-12'));

    expect(fn () => $this->action->handle(
        $booking, Carbon::parse('2030-09-20'), Carbon::parse('2030-09-22'),
    ))->toThrow(RuntimeException::class);
});

it('rejects when the booking is cancelled', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create();

    expect(fn () => $this->action->handle(
        $booking, Carbon::parse('2030-09-10'), Carbon::parse('2030-09-12'),
    ))->toThrow(RuntimeException::class);
});

it('allows a new request once the prior one is decided', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    $first = $this->action->handle($booking, Carbon::parse('2030-09-10'), Carbon::parse('2030-09-12'));
    $first->forceFill(['status' => BookingChangeRequest::STATUS_REJECTED])->save();

    $second = $this->action->handle($booking, Carbon::parse('2030-09-20'), Carbon::parse('2030-09-22'));

    expect($second->id)->not->toBe($first->id);
    expect($second->status)->toBe(BookingChangeRequest::STATUS_PENDING);
});
