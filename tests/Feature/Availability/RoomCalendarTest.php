<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Livewire\Dashboard\Calendar\RoomView;
use App\Models\AvailabilityBlock;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'calendar-room']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders the room calendar at /dashboard/rooms/{room}/calendar', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://calendar-room.keverna.test/dashboard/rooms/'.$this->room->id.'/calendar');

    $response->assertOk();
    $response->assertSeeLivewire(RoomView::class);
});

it('renders the month grid with one cell per day', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(RoomView::class, ['room' => $this->room, 'month' => '2026-07'])
        ->assertSeeHtml('data-testid="cell-1"')
        ->assertSeeHtml('data-testid="cell-15"')
        ->assertSeeHtml('data-testid="cell-31"');
});

it('blocks a date range inclusively (UI semantics) and persists Blocked rows', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(RoomView::class, ['room' => $this->room])
        ->set('block_start', '2026-07-10')
        ->set('block_end', '2026-07-13')  // inclusive end → 4 nights
        ->set('block_reason', 'Owner stay')
        ->call('block')
        ->assertHasNoErrors();

    expect(AvailabilityBlock::where('room_id', $this->room->id)->count())->toBe(4);
    expect(AvailabilityBlock::pluck('type')->unique()->all())->toBe([AvailabilityBlockType::Blocked]);
    expect(AvailabilityBlock::pluck('reason')->unique()->all())->toBe(['Owner stay']);
});

it('surfaces RangeNotAvailable as a validation error on overlap', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2026-07-11']);

    Livewire::actingAs($this->operator, 'web')
        ->test(RoomView::class, ['room' => $this->room])
        ->set('block_start', '2026-07-10')
        ->set('block_end', '2026-07-12')
        ->call('block')
        ->assertHasErrors(['block_start']);
});

it('unblocks a range and removes only Blocked rows', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->createMany([
            ['date' => '2026-07-10', 'type' => AvailabilityBlockType::Blocked],
            ['date' => '2026-07-11', 'type' => AvailabilityBlockType::Blocked],
        ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2026-07-12']);

    Livewire::actingAs($this->operator, 'web')
        ->test(RoomView::class, ['room' => $this->room])
        ->set('block_start', '2026-07-10')
        ->set('block_end', '2026-07-12') // inclusive 10..12
        ->call('unblock')
        ->assertHasNoErrors();

    // The Booked row survives; the 2 Blocked rows are gone.
    $remaining = AvailabilityBlock::where('room_id', $this->room->id)->get();
    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->type)->toBe(AvailabilityBlockType::Booked);
});

it('rejects block_end before block_start', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(RoomView::class, ['room' => $this->room])
        ->set('block_start', '2026-07-15')
        ->set('block_end', '2026-07-10')
        ->call('block')
        ->assertHasErrors(['block_end']);
});

it('404s when an operator opens the calendar for a foreign tenant\'s room', function (): void {
    $other = Tenant::factory()->create(['slug' => 'other-cal']);
    $otherProp = Property::factory()->for($other)->create();
    Tenant::forgetCurrent();
    $other->makeCurrent();
    $foreignRoom = Room::factory()->for($other)->for($otherProp)->create();
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://calendar-room.keverna.test/dashboard/rooms/'.$foreignRoom->id.'/calendar');

    $response->assertNotFound();
});
