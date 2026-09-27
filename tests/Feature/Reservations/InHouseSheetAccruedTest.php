<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
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
 * "In-house right now" sheet on the Reservations page - Total
 * accrued balance line. Regression against the bug where
 * buildInHouseSheetData eager-loaded room.category WITHOUT
 * base_rate, so $room->base_rate (an accessor that reads
 * $room->category->base_rate) returned 0 and unchargedOverstayAmount
 * multiplied by 0 → the sheet always read ₦0 accrued even when the
 * dashboard's per-row "Currently in-house" list showed money owed.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2027-05-15 10:00:00');
    $this->tenant = Tenant::factory()->create(['slug' => 'inhouse-sheet']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create();
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($this->operator, 'web');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('rolls unbilled overstay into the sheet total_accrued', function (): void {
    // Guest is 2 nights past their booked checkout and still CheckedIn.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Overdue Ada',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2027-05-10',
        'checkout_date' => '2027-05-13',
        'nights' => 3,
        'room_total' => 120_000,
        'total_amount' => 120_000,
        'balance_due' => 0,
    ]);

    Livewire::test(ReservationsIndex::class)
        ->assertViewHas('inHouseSheetData', function (array $data): bool {
            // 2 nights × ₦40k base = ₦80k unbilled overstay.
            expect((int) $data['total_accrued'])->toBe(80_000);
            return true;
        });
});
