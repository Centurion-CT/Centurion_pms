<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Day-detail modal is date-aware: past days show history semantics
 * ("Occupied", "No-show", "—") not live state ("Available", "Arriving"),
 * and the "Make reservation" CTA disappears — you can't book yesterday.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2027-05-15 10:00:00');

    $this->tenant = Tenant::factory()->create(['slug' => 'dd-date']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create([
        'room_number' => '101',
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($this->operator, 'web');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('past-day rooms with no booking read as "—" (past_free), NOT "Available"', function (): void {
    $past = Carbon::today()->subDays(3)->toDateString();

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $past)
        ->assertViewHas('dayDetailData', function (array $data): bool {
            return $data['is_past'] === true
                && collect($data['rooms'])->every(fn ($r) => $r['status'] === 'past_free');
        });
});

it('past-day room with a checked-out booking reads as "Occupied", not filtered out', function (): void {
    $stayDate = Carbon::today()->subDays(3);
    // Historical booking — guest stayed on that day, has since checked out.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'checkin_date' => $stayDate,
        'checkout_date' => $stayDate->copy()->addDay(),
        'guest_name' => 'Historical Guest',
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $stayDate->toDateString())
        ->assertViewHas('dayDetailData', function (array $data): bool {
            $row = collect($data['rooms'])->firstWhere('status', 'occupied');
            return $data['is_past'] === true
                && $row !== null
                && $row['booking']->guest_name === 'Historical Guest';
        });
});

it('past-day booking that never checked in reads as "No-show", not "Arriving"', function (): void {
    $stayDate = Carbon::today()->subDays(2);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed, // never got to CheckedIn
        'checkin_date' => $stayDate,
        'checkout_date' => $stayDate->copy()->addDay(),
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $stayDate->toDateString())
        ->assertViewHas('dayDetailData', function (array $data): bool {
            $row = collect($data['rooms'])->firstWhere('booking', '!=', null);
            return $data['is_past'] === true && $row['status'] === 'no_show';
        });
});

it('today keeps the existing arriving / in_house / departing semantics', function (): void {
    // Guest already checked in, leaves later — "In-house".
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', Carbon::today()->toDateString())
        ->assertViewHas('dayDetailData', function (array $data): bool {
            $row = collect($data['rooms'])->firstWhere('booking', '!=', null);
            return $data['is_past'] === false
                && $row['status'] === 'in_house';
        });
});

it('switchToReserveFromDayDetail refuses when dayDetailDate is in the past', function (): void {
    $past = Carbon::today()->subDays(2)->toDateString();

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $past)
        ->call('switchToReserveFromDayDetail')
        ->assertSet('dayDetailMode', 'summary'); // did NOT flip to 'reserve'
});

it('past-day KPI Free tile reads "—" not a room count (misleading)', function (): void {
    $past = Carbon::today()->subDays(3)->toDateString();

    Livewire::test(ReservationsIndex::class)
        ->call('loadDayDetail', $past)
        ->assertViewHas('dayDetailData', function (array $data): bool {
            return $data['is_past'] === true
                && (int) $data['kpi']['free'] === 0;
        });
});
