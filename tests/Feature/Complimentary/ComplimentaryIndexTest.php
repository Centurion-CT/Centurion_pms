<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Complimentary\Index as ComplimentaryIndex;
use App\Models\Booking;
use App\Models\BookingBreakfastService;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Complimentary breakfast roster — kitchen morning pull + front desk
 * dining-room lookup. Every guest whose booking carries
 * breakfast_included AND whose stay covers the picked day shows up.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'comp-breakfast']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 50_000,
        'breakfast_included_rate' => 55_000,
        'min_nights' => 1,
    ]);
    $this->r101 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'room_number' => '101',
        'status' => RoomStatus::Active->value,
    ]);
    $this->r306 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'room_number' => '306',
        'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('lists guests whose booking carries breakfast_included for today', function (): void {
    Booking::factory()->for($this->tenant)->for($this->r101)->create([
        'guest_name' => 'Ngozi with Breakfast',
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    // Same day, but no breakfast — should NOT appear.
    Booking::factory()->for($this->tenant)->for($this->r306)->create([
        'guest_name' => 'Ada Room Only',
        'breakfast_included' => false,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::test(ComplimentaryIndex::class)
        ->assertSee('Ngozi with Breakfast')
        ->assertDontSee('Ada Room Only');
});

it('excludes Cancelled / CheckedOut / NoShow bookings even if breakfast was included', function (): void {
    foreach ([BookingStatus::Cancelled, BookingStatus::CheckedOut, BookingStatus::NoShow] as $status) {
        Booking::factory()->for($this->tenant)->for($this->r101)->create([
            'guest_name' => 'Stale · '.$status->value,
            'breakfast_included' => true,
            'booking_status' => $status,
            'checkin_date' => Carbon::today()->subDay(),
            'checkout_date' => Carbon::today()->addDay(),
        ]);
    }

    Livewire::test(ComplimentaryIndex::class)
        ->assertDontSee('Stale · cancelled')
        ->assertDontSee('Stale · checked_out')
        ->assertDontSee('Stale · no_show');
});

it('picks a different day when the date filter changes', function (): void {
    // Guest A stays yesterday only. Guest B stays a week from now.
    Booking::factory()->for($this->tenant)->for($this->r101)->create([
        'guest_name' => 'Yesterday Guest',
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(2),
        'checkout_date' => Carbon::today(),           // out today, so today NOT covered
    ]);
    Booking::factory()->for($this->tenant)->for($this->r306)->create([
        'guest_name' => 'Next Week Guest',
        'breakfast_included' => true,
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDays(6),
        'checkout_date' => Carbon::today()->addDays(9),
    ]);

    // Default = today → neither should appear.
    Livewire::test(ComplimentaryIndex::class)
        ->assertDontSee('Yesterday Guest')
        ->assertDontSee('Next Week Guest');

    // Snap to a day in the "Next Week Guest" stay.
    Livewire::test(ComplimentaryIndex::class)
        ->set('date', Carbon::today()->addDays(7)->toDateString())
        ->assertSee('Next Week Guest')
        ->assertDontSee('Yesterday Guest');
});

it('narrows results by room-number substring search', function (): void {
    Booking::factory()->for($this->tenant)->for($this->r101)->create([
        'guest_name' => 'On Floor One',
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    Booking::factory()->for($this->tenant)->for($this->r306)->create([
        'guest_name' => 'On Floor Three',
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::test(ComplimentaryIndex::class)
        ->set('roomSearch', '306')
        ->assertSee('On Floor Three')
        ->assertDontSee('On Floor One');
});

it('toggleServed marks a booking as served for the viewed day', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->r101)->create([
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::test(ComplimentaryIndex::class)
        ->call('toggleServed', $b->id);

    $service = BookingBreakfastService::query()->first();
    expect($service)->not->toBeNull();
    expect($service->booking_id)->toBe($b->id);
    expect($service->service_date->toDateString())->toBe(Carbon::today()->toDateString());
    expect($service->served_by_operator_id)->toBe($this->manager->id);
});

it('toggleServed a second time removes the marker (idempotent flip)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->r101)->create([
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::test(ComplimentaryIndex::class)
        ->call('toggleServed', $b->id)
        ->call('toggleServed', $b->id);

    expect(BookingBreakfastService::query()->count())->toBe(0);
});

it('served state is scoped to the viewed day (per-morning marker)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->r101)->create([
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(2),
        'checkout_date' => Carbon::today()->addDays(2),
    ]);

    // Mark served yesterday.
    Livewire::test(ComplimentaryIndex::class)
        ->set('date', Carbon::today()->subDay()->toDateString())
        ->call('toggleServed', $b->id);

    // Today should still show as unserved.
    Livewire::test(ComplimentaryIndex::class)
        ->assertViewHas('servedByBookingId', fn ($map) => $map->isEmpty());

    // Total service rows across all days = 1 (yesterday only).
    expect(BookingBreakfastService::query()->count())->toBe(1);
});

it('exposes servedByBookingId + servedCount for the viewed day', function (): void {
    $b1 = Booking::factory()->for($this->tenant)->for($this->r101)->create([
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    $b2 = Booking::factory()->for($this->tenant)->for($this->r306)->create([
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    BookingBreakfastService::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $b1->id,
        'service_date' => Carbon::today()->toDateString(),
        'served_at' => Carbon::now(),
    ]);

    Livewire::test(ComplimentaryIndex::class)
        ->assertViewHas('servedCount', 1)
        ->assertViewHas('servedByBookingId', fn ($map) => $map->has($b1->id) && ! $map->has($b2->id));
});

it('surfaces the KPI covers for today / tomorrow / next 7 days', function (): void {
    // Today: 1 cover
    Booking::factory()->for($this->tenant)->for($this->r101)->create([
        'guest_name' => 'Today',
        'breakfast_included' => true,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),   // covers today
    ]);
    // Tomorrow: 1 additional cover (same room won't work — dates would clash)
    Booking::factory()->for($this->tenant)->for($this->r306)->create([
        'guest_name' => 'Tomorrow',
        'breakfast_included' => true,
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDay(),
        'checkout_date' => Carbon::today()->addDays(3),  // covers tomorrow + 2 more
    ]);

    Livewire::test(ComplimentaryIndex::class)
        ->assertViewHas('ambientCounts', function ($counts) {
            // Today: only "Today" guest = 1
            // Tomorrow: "Today" (covers tomorrow — checkout=today+1 exclusive) + "Tomorrow" = 2
            // Actually "Today" guest checkout is today+1, so tomorrow (today+1) is
            // NOT covered ("<= day AND > day" — checkout_date > today+1 is false).
            // So tomorrow = just "Tomorrow" guest = 1.
            return $counts['today'] === 1
                && $counts['tomorrow'] === 1;
        });
});
