<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateCategoryReservation;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Home;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * DashboardSnapshot + the operator home page must surface category-
 * only (unassigned) reservations. Prior to this the snapshot's
 * scopeBookings walked whereHas('room', ...) so room_id=NULL bookings
 * fell out of arrivals + pulse counts; the front desk had no way of
 * knowing there were work items waiting from the dashboard.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'dash-unassigned']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 30_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('DashboardSnapshot exposes unassigned counts on the pulse', function (): void {
    // Two category-only bookings: one arrives today, one arrives
    // tomorrow. Both count in the total; only today's counts in
    // unassigned_today.
    app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'Today Guest', guestEmail: '', guestPhone: '+2348011117777',
        adults: 1, children: 0,
    );
    app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::tomorrow(),
        checkout: Carbon::tomorrow()->addDay(),
        guestName: 'Tomorrow Guest', guestEmail: '', guestPhone: '+2348011118888',
        adults: 1, children: 0,
    );

    $data = app(DashboardSnapshot::class)->build(bypass: true);

    expect($data['pulse']['unassigned'])->toBe(2);
    expect($data['pulse']['unassigned_today'])->toBe(1);
    expect($data['today']['unassignedArrivals']->count())->toBe(1);
    // Pulse arriving folds unassigned in so the operator sees the
    // true count of guests due today.
    expect($data['pulse']['arriving'])->toBe(1);   // 1 unassigned today
});

it('never renders a standalone Unassigned pulse tile even when the count > 0', function (): void {
    // Deliberate design choice: the pulse strip stays at four
    // canonical KPIs (In-house / Arriving / Departing / Outstanding).
    // Unassigned reservations surface via the Arriving sub-label +
    // the Needs attention widget instead.
    app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'Test', guestEmail: '', guestPhone: '+2348011119999',
        adults: 1, children: 0,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertDontSee('data-testid="pulse-tile-unassigned"', false);
});

it('shows the unassigned count in the Arriving tile\'s sub-label', function (): void {
    app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'Sub Guest', guestEmail: '', guestPhone: '+2348011119911',
        adults: 1, children: 0,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertSee('1 unassigned');
});

it('folds unassigned arrivals into the "Today at a glance" schedule', function (): void {
    // One category-only booking arriving today. Should show up on
    // the schedule with "Category · Unassigned" instead of "Rm -".
    app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'Glance Guest', guestEmail: '', guestPhone: '+2348011117733',
        adults: 1, children: 0,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertSee('Glance Guest')
        ->assertSee('Deluxe')
        ->assertSee('Unassigned');
});

it('adds an "Unassigned reservations arriving today" item to Needs attention', function (): void {
    app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'Attention Guest', guestEmail: '', guestPhone: '+2348011115555',
        adults: 1, children: 0,
    );

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertSee('Unassigned reservations arriving today');
});
