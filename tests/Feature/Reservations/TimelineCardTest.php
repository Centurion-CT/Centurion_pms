<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The reservation-show Timeline card surfaces the lifecycle
 * timestamps (booked, confirmed, checked in, payment received,
 * extended, checked out, ...) sourced from activity_log. Prior to
 * this the page only showed the arrival + departure dates - front
 * desk + accountant had no idea WHEN the guest actually walked in
 * or when each payment came in.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'timeline-card']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active]);
    $this->linda = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk, 'name' => 'Linda Ijeoma',
    ]);
    $this->actingAs($this->linda, 'web');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('renders a Booked entry sourced from the booking\'s created_at', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'created_at' => Carbon::create(2026, 9, 7, 13, 22, 0),
    ]);

    $r = Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref]);

    $r->assertSee('data-testid="reservation-timeline"', false)
        ->assertSee('data-testid="timeline-created"', false)
        ->assertSee('Booked')
        ->assertSee('7 Sep · 1:22 PM');
});

it('renders a Payment received entry for each manual_payment_recorded activity', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'created_at' => Carbon::create(2026, 9, 7, 13, 20, 0),
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 7, 13, 22, 16));
    activity('booking')->causedBy($this->linda)->performedOn($b)->event('manual_payment_recorded')
        ->withProperties(['amount' => 100_000, 'method' => 'transfer'])
        ->log('t');

    $r = Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref]);
    $r->assertSee('data-testid="timeline-paid"', false)
        ->assertSee('Payment received')
        ->assertSee('₦100,000 · transfer · by Linda Ijeoma')
        ->assertSee('7 Sep · 1:22 PM');
});

it('renders Checked in with the operator name + the actual check-in time', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'created_at' => Carbon::create(2026, 9, 7, 13, 20, 0),
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 7, 14, 30, 0));
    activity('booking')->causedBy($this->linda)->performedOn($b)->event('checked_in')->log('t');

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->assertSee('data-testid="timeline-checked_in"', false)
        ->assertSee('Checked in')
        ->assertSee('by Linda Ijeoma')
        ->assertSee('7 Sep · 2:30 PM');
});

it('lists an Extended entry with the extension amount', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'created_at' => Carbon::create(2026, 9, 7, 13, 20, 0),
        'booking_status' => BookingStatus::CheckedIn,
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 8, 11, 29, 0));
    activity('booking')->causedBy($this->linda)->performedOn($b)->event('extended')
        ->withProperties(['extension_charged' => 100_000])
        ->log('t');

    Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->assertSee('data-testid="timeline-extended"', false)
        ->assertSee('Extended')
        ->assertSee('+₦100,000 · by Linda Ijeoma')
        ->assertSee('8 Sep · 11:29 AM');
});

it('orders entries chronologically (Booked → Paid → Checked in)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'created_at' => Carbon::create(2026, 9, 7, 13, 20, 0),
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 7, 13, 22, 0));
    activity('booking')->causedBy($this->linda)->performedOn($b)->event('manual_payment_recorded')
        ->withProperties(['amount' => 100_000, 'method' => 'cash'])->log('t1');
    Carbon::setTestNow(Carbon::create(2026, 9, 7, 15, 0, 0));
    activity('booking')->causedBy($this->linda)->performedOn($b)->event('checked_in')->log('t2');

    $html = Livewire::test(ReservationShow::class, ['bookingRef' => $b->booking_ref])->html();

    // Order in the HTML: Booked (13:20) → Paid (13:22) → Checked in (15:00).
    $bookedPos = strpos($html, 'data-testid="timeline-created"');
    $paidPos = strpos($html, 'data-testid="timeline-paid"');
    $checkedInPos = strpos($html, 'data-testid="timeline-checked_in"');
    expect($bookedPos)->toBeLessThan($paidPos);
    expect($paidPos)->toBeLessThan($checkedInPos);
});
