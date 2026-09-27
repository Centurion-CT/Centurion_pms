<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Bookings\ResendBookingConfirmation;
use App\Enums\AvailabilityBlockType;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Events\BookingConfirmed;
use App\Models\Activity;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'op-actions']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create(['name' => 'Op One']);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/* ---------- ConfirmBooking ---------- */

it('flips a Pending booking to Confirmed and writes an audit row', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
    ]);

    app(ConfirmBooking::class)->handle($b, $this->operator);

    expect($b->fresh()->booking_status)->toBe(BookingStatus::Confirmed);

    $row = Activity::query()->where('subject_id', $b->id)->latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->event)->toBe('confirmed');
    expect($row->causer_id)->toBe($this->operator->id);
    expect($row->tenant_id)->toBe($this->tenant->id);
});

it('refuses to confirm a Cancelled booking', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create();
    expect(fn () => app(ConfirmBooking::class)->handle($b, $this->operator))
        ->toThrow(RuntimeException::class);
});

/* ---------- MarkCheckedIn ---------- */

it('checks in a Confirmed booking when today >= checkin date', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    app(MarkCheckedIn::class)->handle($b, $this->operator);

    expect($b->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
    expect(Activity::query()->where('event', 'checked_in')->where('subject_id', $b->id)->exists())
        ->toBeTrue();
});

it('refuses to check in before the checkin date', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-10 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    expect(fn () => app(MarkCheckedIn::class)->handle($b, $this->operator))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to check in a Cancelled booking', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create();
    expect(fn () => app(MarkCheckedIn::class)->handle($b, $this->operator))
        ->toThrow(RuntimeException::class);
});

/* ---------- MarkCheckedOut ---------- */

it('checks out from CheckedIn and deletes only Booked future blocks', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-18',
        'booking_status' => BookingStatus::CheckedIn,
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2030-09-16', 'booking_id' => $b->id]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create(['date' => '2030-09-17', 'type' => AvailabilityBlockType::Blocked]);

    app(MarkCheckedOut::class)->handle($b, $this->operator);

    expect($b->fresh()->booking_status)->toBe(BookingStatus::CheckedOut);
    expect(AvailabilityBlock::count())->toBe(1);
    expect(AvailabilityBlock::first()->type)->toBe(AvailabilityBlockType::Blocked);
});

it('allows direct check-out from Confirmed (operator forgot to check in)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    app(MarkCheckedOut::class)->handle($b, $this->operator);
    expect($b->fresh()->booking_status)->toBe(BookingStatus::CheckedOut);
});

it('tops up overstay at checkout when the auto-cron under-billed a partial stay', function (): void {
    // Regression: MarkCheckedOut used to bail out the moment ANY
    // overstay charge existed, so a guest whose nightly cron only
    // captured 1 of 5 overstay nights would walk out ₦120k short.
    // Now it adds a top-up charge for the still-uncharged delta.
    Carbon::setTestNow(Carbon::parse('2030-09-20 10:00:00'));

    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 30_000,
    ]);
    $room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')->create();
    $b = Booking::factory()->for($this->tenant)->for($room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-10',
        'checkout_date' => '2030-09-15',  // 5 nights over today
    ]);
    // Auto-cron only captured 1 night before missing the rest.
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $b->id,
        'created_by_operator_id' => null,
        'type' => BookingChargeType::Overstay,
        'description' => '1 night overstay (auto)',
        'amount' => 30_000,
        'settled' => true,
    ]);

    // The top-up runs BEFORE the outstanding guard, so MarkCheckedOut
    // throws once the new charge is on the ledger — that's the point:
    // no operator can slip an under-billed checkout past the guard.
    // Catch the expected throw and then assert on the charges the
    // action DID create before it aborted.
    expect(fn () => app(MarkCheckedOut::class)->handle($b->fresh(), $this->operator))
        ->toThrow(RuntimeException::class);

    $overstayCharges = BookingCharge::query()
        ->where('booking_id', $b->id)
        ->where('type', BookingChargeType::Overstay->value)
        ->orderBy('id')
        ->get();

    expect($overstayCharges->count())->toBe(2);
    // 4 top-up nights × ₦30k = ₦120k.
    expect($overstayCharges->last()->amount)->toBe(120_000);
    expect($overstayCharges->last()->description)->toContain('4 nights overstay');
    expect($overstayCharges->sum('amount'))->toBe(150_000);
});

/* ---------- Operator-side CancelBooking with refund flag ---------- */

it('records refund-requested flag in audit properties', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    app(CancelBooking::class)->handle($b, $this->operator, refundRequested: true);

    $row = Activity::query()->where('event', 'cancelled')->where('subject_id', $b->id)->first();
    expect($row)->not->toBeNull();
    expect($row->causer_id)->toBe($this->operator->id);
    expect($row->properties->get('refund_requested'))->toBeTrue();
    expect($row->description)->toContain('refund requested');
});

it('refuses to cancel a CheckedIn booking — operator must Check out instead', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-08-30',
        'checkout_date' => '2030-09-02',
    ]);

    expect(fn () => app(CancelBooking::class)->handle($b, $this->operator))
        ->toThrow(\RuntimeException::class, 'already checked in');
    expect($b->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
});

it('omits the refund tag when the operator does not check the box', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    app(CancelBooking::class)->handle($b, $this->operator);

    $row = Activity::query()->where('event', 'cancelled')->where('subject_id', $b->id)->first();
    expect($row->properties->get('refund_requested'))->toBeFalse();
    expect($row->description)->not->toContain('refund requested');
});

/* ---------- ResendBookingConfirmation ---------- */

it('dispatches a BookingConfirmed event and writes an audit row', function (): void {
    // Targeted fake — Event::fake() (no args) would swallow Eloquent's
    // creating event and break the activity_log tenant-stamp closure.
    Event::fake([BookingConfirmed::class]);

    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    app(ResendBookingConfirmation::class)->handle($b, $this->operator);

    Event::assertDispatched(BookingConfirmed::class, fn ($e) => $e->booking->id === $b->id);

    $row = Activity::query()->where('event', 'resent_confirmation')->where('subject_id', $b->id)->first();
    expect($row)->not->toBeNull();
    expect($row->causer_id)->toBe($this->operator->id);
});

/* ---------- Activity-level multi-tenant isolation ---------- */

it('does not let an operator see another tenant\'s audit rows', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'payment_status' => PaymentStatus::Pending,
    ]);
    app(ConfirmBooking::class)->handle($b, $this->operator);
    expect(Activity::query()->count())->toBeGreaterThan(0);

    // Switch to tenant B and assert the activity_log query returns zero.
    Tenant::forgetCurrent();
    $otherTenant = Tenant::factory()->create(['slug' => 'op-actions-other']);
    $otherTenant->makeCurrent();

    expect(Activity::query()->count())->toBe(0);
});
