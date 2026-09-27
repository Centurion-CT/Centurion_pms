<?php

declare(strict_types=1);

use App\Actions\Bookings\ConfirmBookingPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Events\BookingConfirmed;
use App\Mail\Bookings\BookingConfirmedMail;
use App\Mail\Bookings\BookingOnHoldMail;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'notif-test', 'email' => 'gm@hotel.test',
        'paystack_secret_key' => 'sk',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('dispatches BookingConfirmed event when a Full payment succeeds', function (): void {
    Event::fake();

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 50_000,
    ]);
    $p = Payment::factory()->for($this->tenant)->for($b)->create([
        'amount' => 50_000, 'type' => PaymentType::Full,
    ]);

    app(ConfirmBookingPayment::class)->handle($p);

    Event::assertDispatched(BookingConfirmed::class, fn ($e) => $e->booking->id === $b->id);
});

it('does NOT re-dispatch BookingConfirmed on the second (idempotent) call', function (): void {
    Event::fake();

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $p = Payment::factory()->for($this->tenant)->for($b)->create();

    app(ConfirmBookingPayment::class)->handle($p);
    app(ConfirmBookingPayment::class)->handle($p->refresh());

    Event::assertDispatchedTimes(BookingConfirmed::class, 1);
});

it('queues a BookingConfirmedMail to the guest AND a copy to the operator', function (): void {
    Mail::fake();

    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_email' => 'guest@x.test',
    ]);

    BookingConfirmed::dispatch($b);

    Mail::assertQueued(BookingConfirmedMail::class, fn ($m) => $m->hasTo('guest@x.test'));
    Mail::assertQueued(BookingConfirmedMail::class, fn ($m) => $m->hasTo('gm@hotel.test'));
});

it('queues BookingOnHoldMail (not Confirmed) when the booking is on hold', function (): void {
    Mail::fake();

    $b = Booking::factory()->for($this->tenant)->for($this->room)
        ->create([
            'total_amount' => 100_000,
            'deposit_amount' => 30_000,
            'balance_due' => 70_000,
            'booking_status' => BookingStatus::OnHold,
            'payment_status' => PaymentStatus::Succeeded,
            'guest_email' => 'hold@x.test',
        ]);

    BookingConfirmed::dispatch($b);

    Mail::assertQueued(BookingOnHoldMail::class, fn ($m) => $m->hasTo('hold@x.test'));
    Mail::assertNotQueued(BookingConfirmedMail::class);
});
