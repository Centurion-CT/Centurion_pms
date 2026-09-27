<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'day-detail']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 4, 'capacity_children' => 2, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'status' => RoomStatus::Active->value,
        'room_number' => '101',
    ]);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'status' => RoomStatus::Active->value,
        'room_number' => '102',
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->operator, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('opens the day-detail modal in summary mode and lists rooms with statuses', function (): void {
    $day = Carbon::today()->addDays(3);

    // roomA has a checked-in booking on that day, roomB is closed.
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'guest_name'     => 'Ada Okoye',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date'   => $day->copy()->subDay(),
        'checkout_date'  => $day->copy()->addDay(),
    ]);
    AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id, 'room_id' => $this->roomB->id,
        'date' => $day->toDateString(), 'type' => AvailabilityBlockType::Blocked->value,
        'reason' => 'Plumbing',
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $day->toDateString())
        ->assertSet('dayDetailMode', 'summary')
        ->assertViewHas('dayDetailData', function ($data) use ($day) {
            $rooms = $data['rooms'];
            $roomA = $rooms->firstWhere('room.room_number', '101');
            $roomB = $rooms->firstWhere('room.room_number', '102');

            return $data['date']->toDateString() === $day->toDateString()
                && $roomA['status'] === 'in_house'
                && $roomA['booking']?->guest_name === 'Ada Okoye'
                && $roomB['status'] === 'blocked'
                && $roomB['block']?->reason === 'Plumbing'
                && $data['kpi']['in_house'] === 1
                && $data['kpi']['blocked'] === 1;
        });
});

it('labels a Confirmed (not-yet-checked-in) booking as Arriving, not In-house', function (): void {
    $day = Carbon::today()->addDays(2);

    // Confirmed booking that spans the day, but guest was never marked
    // CheckedIn — must read as "Arriving" (they haven't turned up yet).
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'guest_name'     => 'Ngozi Umeh',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => $day->copy()->subDay(),   // arrived-on-paper yesterday
        'checkout_date'  => $day->copy()->addDay(),
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $day->toDateString())
        ->assertViewHas('dayDetailData', function ($data) {
            $roomA = $data['rooms']->firstWhere('room.room_number', '101');

            return $roomA['status'] === 'arriving'
                && $roomA['booking']?->guest_name === 'Ngozi Umeh';
        });
});

it('excludes CheckedOut + NoShow bookings from the day summary', function (): void {
    $day = Carbon::today()->addDays(1);

    // A checked-out booking on the same room + range must not surface.
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'checkin_date'   => $day->copy()->subDay(),
        'checkout_date'  => $day->copy()->addDay(),
    ]);
    // Same for a NoShow.
    Booking::factory()->for($this->tenant)->for($this->roomB)->create([
        'booking_status' => BookingStatus::NoShow,
        'checkin_date'   => $day->copy()->subDay(),
        'checkout_date'  => $day->copy()->addDay(),
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $day->toDateString())
        ->assertViewHas('dayDetailData', function ($data) {
            // Both rooms should read as free — the CheckedOut / NoShow
            // bookings are inert history and shouldn't dress the row.
            $roomA = $data['rooms']->firstWhere('room.room_number', '101');
            $roomB = $data['rooms']->firstWhere('room.room_number', '102');

            return $roomA['status'] === 'free' && $roomA['booking'] === null
                && $roomB['status'] === 'free' && $roomB['booking'] === null;
        });
});

it('arrivals KPI only counts bookings whose checkin_date equals the viewed day', function (): void {
    $day = Carbon::today()->addDays(3);

    // Arriving today — Confirmed, checkin_date == day.
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => $day->copy(),
        'checkout_date'  => $day->copy()->addDay(),
    ]);
    // Confirmed but "arrived" a day earlier and hasn't shown up — the
    // classifier still labels this "Arriving" on the badge, but it must
    // NOT inflate today's arrivals KPI (it wasn't expected today).
    Booking::factory()->for($this->tenant)->for($this->roomB)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => $day->copy()->subDay(),
        'checkout_date'  => $day->copy()->addDay(),
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $day->toDateString())
        ->assertViewHas('dayDetailData', fn ($data) => $data['kpi']['arrivals'] === 1);
});

it('switching to reserve mode from day-detail flips mode without opening the standalone sheet', function (): void {
    $day = Carbon::today()->addDays(5);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $day->toDateString())
        ->call('switchToReserveFromDayDetail')
        ->assertSet('dayDetailMode', 'reserve')
        ->assertSet('reserveSheetOpen', false)   // no double modal
        ->assertSet('reserveCheckin', $day->toDateString())
        ->assertSet('reserveCheckout', $day->copy()->addDay()->toDateString());
});

it('back-to-summary flips the mode back', function (): void {
    $day = Carbon::today()->addDays(6);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $day->toDateString())
        ->call('switchToReserveFromDayDetail')
        ->call('backToDaySummary')
        ->assertSet('dayDetailMode', 'summary')
        ->assertSet('reserveSheetOpen', false);
});

it('global search finds a booking by ref, phone, and guest name', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_ref' => 'KV-ABC123',
        'guest_name'  => 'Chidi Nweke',
        'guest_phone' => '08055551234',
        'guest_email' => 'chidi@example.test',
    ]);

    // Match by ref
    Livewire::test(ReservationsIndex::class)
        ->set('search', 'KV-ABC')
        ->assertSee('Chidi Nweke')
        ->assertSee('KV-ABC123');

    // Match by phone tail
    Livewire::test(ReservationsIndex::class)
        ->set('search', '5551234')
        ->assertSee('Chidi Nweke');

    // Match by name
    Livewire::test(ReservationsIndex::class)
        ->set('search', 'Chidi')
        ->assertSee('KV-ABC123');
});

it('available-rooms filter excludes rooms with a Booked or Blocked block in the picked range', function (): void {
    $checkin = Carbon::today()->addDays(2);
    $checkout = Carbon::today()->addDays(4);

    // roomA blocked for the range → not available
    AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id, 'room_id' => $this->roomA->id,
        'date' => $checkin->toDateString(), 'type' => AvailabilityBlockType::Blocked->value,
        'reason' => 'Maintenance',
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('openReserveSheet')
        ->set('reserveCheckin', $checkin->toDateString())
        ->set('reserveCheckout', $checkout->toDateString())
        ->assertViewHas('availableRooms', function ($rooms) {
            $ids = $rooms->pluck('id')->all();

            return ! in_array($this->roomA->id, $ids, true)
                && in_array($this->roomB->id, $ids, true);
        });
});
