<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Bookings\Index as BookingsIndex;
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
 * URL filters used by the dashboard's Needs-attention tile:
 *   outstanding=1 → balance_due > 0 on non-closed bookings
 *   overdue=1     → Confirmed / OnHold + checkin_date < today
 * These are the deep links the "click a needs-attention row and see
 * exactly those bookings" behaviour depends on, so if either drifts
 * silently the dashboard tile stops working.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'deep-link']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();

    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($this->operator, 'web');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('outstanding=1 filters to bookings with a balance and hides closed rows', function (): void {
    // Live booking with balance - should show.
    $keeper = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDay(),
        'checkout_date' => Carbon::today()->addDays(2),
        'nights' => 1, 'total_amount' => 40_000, 'room_total' => 40_000, 'balance_due' => 40_000,
    ]);
    // Paid-in-full - should hide.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'balance_due' => 0,
    ]);
    // Cancelled with balance - closed status, should hide.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Cancelled,
        'balance_due' => 10_000,
    ]);

    Livewire::test(BookingsIndex::class)
        ->set('outstandingFilter', '1')
        ->assertSee($keeper->booking_ref)
        ->assertSeeText('Outstanding balance only');
});

it('overdue=1 filters to Confirmed/OnHold with past checkin_date only', function (): void {
    // Overdue Confirmed - should show.
    $overdue = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->subDays(2),
        'checkout_date' => Carbon::today()->subDay(),
    ]);
    // Future Confirmed - should hide.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDay(),
        'checkout_date' => Carbon::today()->addDays(2),
    ]);
    // Already checked-in - should hide (decision made).
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(2),
    ]);

    Livewire::test(BookingsIndex::class)
        ->set('overdueFilter', '1')
        ->assertSee($overdue->booking_ref)
        ->assertSeeText('Overdue arrivals');
});

it('clearFilters wipes both deep-link filters', function (): void {
    Livewire::test(BookingsIndex::class)
        ->set('outstandingFilter', '1')
        ->set('overdueFilter', '1')
        ->call('clearFilters')
        ->assertSet('outstandingFilter', '')
        ->assertSet('overdueFilter', '');
});
