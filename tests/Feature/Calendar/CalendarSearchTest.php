<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Calendar\PropertyView;
use App\Livewire\Dashboard\Home;
use App\Models\AvailabilityBlock;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * HasCalendarSearch trait — quick room-number + availability filter
 * shared by the dashboard home heatmap and the property calendar.
 * Filter narrows the visible rooms; the room-picker on the sheets
 * and the underlying booking data are unaffected.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cal-search']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)
        ->create(['capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 30_000, 'min_nights' => 1]);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->r101 = Room::factory()->for($this->tenant)->for($this->property)
        ->create(['room_category_id' => $this->category->id, 'room_number' => '101', 'status' => RoomStatus::Active->value]);
    $this->r102 = Room::factory()->for($this->tenant)->for($this->property)
        ->create(['room_category_id' => $this->category->id, 'room_number' => '102', 'status' => RoomStatus::Active->value]);
    $this->r210 = Room::factory()->for($this->tenant)->for($this->property)
        ->create(['room_category_id' => $this->category->id, 'room_number' => '210', 'status' => RoomStatus::Active->value]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ─── PropertyView (dashboard/calendar) ─────────────────────────── */

it('property calendar shows all rooms when no filter is active', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->assertViewHas('totalRoomCount', 3)
        ->assertViewHas('visibleRoomCount', 3)
        ->assertViewHas('rooms', fn ($rooms) => $rooms->count() === 3);
});

it('property calendar narrows to rooms whose number contains the search text', function (): void {
    // "01" is a strict substring of only 101 in this fixture.
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->set('roomSearch', '01')
        ->assertViewHas('visibleRoomCount', 1)
        ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')->all() === ['101']);
});

it('room-number substring matches every room that contains the text', function (): void {
    // "10" appears inside 101, 102, AND 210 — the front desk uses this
    // to sweep a floor (all "1xx" rooms) in one glance.
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->set('roomSearch', '10')
        ->assertViewHas('visibleRoomCount', 3);
});

it('room-number search is case-insensitive on the letter parts (e.g. Suite A)', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'room_number' => 'Suite A',
        'status' => RoomStatus::Active->value,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->set('roomSearch', 'suite')
        ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')->all() === ['Suite A']);
});

it('only-available hides rooms with any block inside the range', function (): void {
    // Room 101 is booked tonight — should drop out with the default
    // tonight→tomorrow range once onlyAvailable is on.
    AvailabilityBlock::factory()->for($this->tenant)->for($this->r101)
        ->booked()->create(['date' => Carbon::today()->toDateString()]);

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->set('onlyAvailable', true)
        ->assertViewHas('visibleRoomCount', 2)
        ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')
            ->sort()->values()->all() === ['102', '210']);
});

it('only-available respects a custom range', function (): void {
    // Block room 210 five days out. Setting the range around that day
    // should hide 210 while leaving 101 + 102 in.
    $target = Carbon::today()->addDays(5);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->r210)
        ->create(['date' => $target->toDateString(), 'type' => AvailabilityBlockType::Blocked]);

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->set('availFrom', $target->toDateString())
        ->set('availTo', $target->copy()->addDay()->toDateString())
        ->set('onlyAvailable', true)
        ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')
            ->sort()->values()->all() === ['101', '102']);
});

it('room search + only-available compose (AND semantics)', function (): void {
    // Search "1" matches all three (101, 102, 210). Book 101 tonight
    // so onlyAvailable strips it — expecting 102 and 210 to remain.
    AvailabilityBlock::factory()->for($this->tenant)->for($this->r101)
        ->booked()->create(['date' => Carbon::today()->toDateString()]);

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->set('roomSearch', '1')
        ->set('onlyAvailable', true)
        ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')
            ->sort()->values()->all() === ['102', '210']);
});

it('clearCalendarSearch resets everything back to defaults', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->set('roomSearch', '101')
        ->set('onlyAvailable', true)
        ->set('availFrom', '2027-01-01')
        ->set('availTo', '2027-01-05')
        ->call('clearCalendarSearch')
        ->assertSet('roomSearch', '')
        ->assertSet('onlyAvailable', false)
        ->assertSet('availFrom', Carbon::today()->toDateString())
        ->assertSet('availTo', Carbon::today()->addDay()->toDateString());
});

/* ─── Home (dashboard) ──────────────────────────────────────────── */

it('home heatmap filters its rooms + rebuilds the grouped structure', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->set('roomSearch', '102')
        ->assertViewHas('visibleRoomCount', 1)
        ->assertViewHas('heatmap', function ($heatmap) {
            return $heatmap['rooms']->count() === 1
                && $heatmap['rooms']->first()->room_number === '102'
                && $heatmap['grouped']->count() === 1
                && $heatmap['grouped']->first()->rooms->count() === 1;
        });
});

it('home heatmap only-available honours the range picker', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->r102)
        ->booked()->create(['date' => Carbon::today()->addDays(3)->toDateString()]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->set('availFrom', Carbon::today()->addDays(3)->toDateString())
        ->set('availTo', Carbon::today()->addDays(4)->toDateString())
        ->set('onlyAvailable', true)
        ->assertViewHas('heatmap', function ($heatmap) {
            $numbers = $heatmap['rooms']->pluck('room_number')->sort()->values()->all();

            return $numbers === ['101', '210']; // 102 dropped
        });
});
