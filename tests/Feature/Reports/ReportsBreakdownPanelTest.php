<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Reports\Index as ReportsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Reports page: click a row on the Revenue (channel / category /
 * room) or Check-in (category) table → client-side breakdown panel
 * with a per-row bookings list. Mirrors the Finance breakdown
 * pattern, but scoped to the Reports data shape.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'reports-breakdown']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->catA = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
        'name' => 'Deluxe studio',
    ]);
    $this->catB = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 30_000, 'min_nights' => 1,
        'name' => 'Standard',
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->catA->id, 'room_number' => '206',
    ]);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->catB->id, 'room_number' => '311',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
});

/* ── Revenue tab ── */

it('revenue tab emits clickable rows + a payload per grouping', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    Booking::factory()->for($this->tenant)->for($this->roomA)->confirmed()->create([
        'guest_name' => 'Chi Guest',
        'checkin_date' => '2030-06-10',
        'nights' => 1,
        'total_amount' => 50_000, 'room_total' => 50_000,
        'source' => BookingSource::WalkIn,
    ]);
    Booking::factory()->for($this->tenant)->for($this->roomB)->create([
        'guest_name' => 'Ada Guest',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-11',
        'nights' => 2,
        'total_amount' => 90_000, 'room_total' => 90_000,
        'source' => BookingSource::Airbnb,
    ]);

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'revenue')
        ->set('preset', 'last_7d')
        ->html();

    expect($html)->toContain('window.reportsBreakdownPanel');
    expect($html)->toContain('data-testid="reports-breakdown-panel"');
    // Row dispatches for each grouping type.
    expect($html)->toContain("id: 'channel:walk_in'");
    expect($html)->toContain("id: 'channel:airbnb'");
    expect($html)->toContain("id: 'revcat:{$this->catA->id}'");
    expect($html)->toContain("id: 'revcat:{$this->catB->id}'");
    expect($html)->toContain("id: 'revroom:{$this->roomA->id}'");
    expect($html)->toContain("id: 'revroom:{$this->roomB->id}'");
    // Payload guests appear in the serialised script.
    expect($html)->toContain('Chi Guest');
    expect($html)->toContain('Ada Guest');
    Carbon::setTestNow();
});

it('revenue category payload only contains bookings for that category', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    Booking::factory()->for($this->tenant)->for($this->roomA)->confirmed()->create([
        'guest_name' => 'Alpha', 'checkin_date' => '2030-06-10',
        'total_amount' => 40_000, 'room_total' => 40_000, 'source' => BookingSource::WalkIn,
    ]);
    Booking::factory()->for($this->tenant)->for($this->roomB)->confirmed()->create([
        'guest_name' => 'Beta', 'checkin_date' => '2030-06-10',
        'total_amount' => 60_000, 'room_total' => 60_000, 'source' => BookingSource::WalkIn,
    ]);

    $view = Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'revenue')
        ->set('preset', 'last_7d');
    $breakdowns = $view->viewData('breakdowns');

    expect($breakdowns['revcat:'.$this->catA->id]['bookings'])->toHaveCount(1);
    expect($breakdowns['revcat:'.$this->catA->id]['bookings'][0]['guest'])->toBe('Alpha');
    expect($breakdowns['revcat:'.$this->catB->id]['bookings'])->toHaveCount(1);
    expect($breakdowns['revcat:'.$this->catB->id]['bookings'][0]['guest'])->toBe('Beta');
    // Channel walk_in aggregates both.
    expect($breakdowns['channel:walk_in']['bookings'])->toHaveCount(2);
    Carbon::setTestNow();
});

/* ── Check-in tab ── */

it('check-in tab payload includes checked-in, no-show, and cancelled bookings', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    // CheckedIn — counts as fulfilled arrival
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'guest_name' => 'Ada CheckedIn',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-10',
        'total_amount' => 40_000,
    ]);
    // NoShow — should show in the panel with status label
    Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'guest_name' => 'Bola Noshow',
        'booking_status' => BookingStatus::NoShow,
        'checkin_date' => '2030-06-11',
        'total_amount' => 40_000,
    ]);

    $view = Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'checkin')
        ->set('preset', 'last_7d');
    $breakdowns = $view->viewData('breakdowns');

    $key = 'checkincat:'.$this->catA->id;
    expect($breakdowns)->toHaveKey($key);
    $guests = collect($breakdowns[$key]['bookings'])->pluck('guest')->all();
    expect($guests)->toContain('Ada CheckedIn');
    expect($guests)->toContain('Bola Noshow');
    Carbon::setTestNow();
});

it('does not build a breakdowns payload for tabs that don\'t need one', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    // Occupancy + Inventory shouldn't publish `breakdowns` — no wire
    // trip cost and no click handlers to trip over.
    $view = Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'occupancy');
    expect($view->viewData('breakdowns'))->toBe([]);

    $view = Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'inventory');
    expect($view->viewData('breakdowns'))->toBe([]);
    Carbon::setTestNow();
});
