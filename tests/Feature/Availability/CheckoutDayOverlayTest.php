<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Calendar\PropertyView;
use App\Livewire\Dashboard\Home;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * AvailabilityBlocks span [checkin, checkout) — the checkout day
 * itself never has a block. But if the guest is still CheckedIn on
 * their checkout day, the calendar cell for today needs to read as
 * "due_out" (or "overdue" if the day has passed) — otherwise it
 * paints free and the operator can accidentally reserve on top of
 * an in-house guest.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'checkout-overlay']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'room_number' => '306',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

/**
 * Seed a CheckedIn booking with the matching real availability
 * blocks so the calendar third-pass finds it. Blocks stop the day
 * before checkout by design.
 */
function seedCheckedIn(Tenant $tenant, Room $room, Carbon $checkin, Carbon $checkout): Booking
{
    $booking = Booking::factory()->for($tenant)->for($room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date'   => $checkin,
        'checkout_date'  => $checkout,
    ]);
    // One block per [checkin, checkout) night.
    for ($c = $checkin->copy(); $c->lessThan($checkout); $c->addDay()) {
        AvailabilityBlock::factory()->for($tenant)->for($room)->booked()->create([
            'date'       => $c->toDateString(),
            'booking_id' => $booking->id,
        ]);
    }

    return $booking;
}

it('PropertyView paints today as due_out when a CheckedIn guest is due out today', function (): void {
    $today = Carbon::today();
    seedCheckedIn($this->tenant, $this->room, $today->copy()->subDay(), $today);

    Livewire::test(PropertyView::class)
        ->assertViewHas('matrix', function ($matrix) use ($today) {
            $todayIso = $today->toDateString();
            return ($matrix[$this->room->id][$todayIso] ?? null) === 'due_out';
        });
});

it('PropertyView paints today as overdue when the CheckedIn guest\'s checkout was yesterday', function (): void {
    $today = Carbon::today();
    seedCheckedIn(
        $this->tenant,
        $this->room,
        $today->copy()->subDays(3),
        $today->copy()->subDay(),
    );

    Livewire::test(PropertyView::class)
        ->assertViewHas('matrix', function ($matrix) use ($today) {
            $todayIso = $today->toDateString();

            return ($matrix[$this->room->id][$todayIso] ?? null) === 'overdue';
        });
});

it('a fresh new booking arriving today still wins over the overlay', function (): void {
    $today = Carbon::today();
    // Yesterday's guest hasn't been checked out yet (overdue) — but
    // a NEW booking is arriving today and has a real block. The real
    // block should take precedence in the matrix so the new arrival
    // renders correctly.
    seedCheckedIn(
        $this->tenant,
        $this->room,
        $today->copy()->subDays(2),
        $today->copy()->subDay(),
    );
    // New arrival — a real Booked block on today.
    $arrival = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => $today,
        'checkout_date'  => $today->copy()->addDay(),
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()->create([
        'date'       => $today->toDateString(),
        'booking_id' => $arrival->id,
    ]);

    Livewire::test(PropertyView::class)
        ->assertViewHas('matrix', function ($matrix) use ($today) {
            $todayIso = $today->toDateString();

            return ($matrix[$this->room->id][$todayIso] ?? null) === 'booked';
        });
});

it('Home heatmap paints today as due_out when a CheckedIn guest is due out today', function (): void {
    $today = Carbon::today();
    seedCheckedIn($this->tenant, $this->room, $today->copy()->subDay(), $today);

    Livewire::test(Home::class)
        ->assertViewHas('heatmap', function ($heatmap) use ($today) {
            $todayIso = $today->toDateString();

            return ($heatmap['blocks'][$this->room->id][$todayIso] ?? null) === 'due_out';
        });
});
