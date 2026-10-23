<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Rooms\Form;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Rooms carry a floor: typed on the room form, or guessed from a
 * hotel-style number (101 -> 1st floor) when left empty.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'rooms-floor']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('guesses the floor only from hotel-style numbers', function (string $number, ?string $floor, ?string $label): void {
    expect(Room::guessFloor($number))->toBe($floor)
        ->and(Room::floorLabel(Room::guessFloor($number)))->toBe($label);
})->with([
    ['101', '1', '1st floor'],
    ['212', '2', '2nd floor'],
    ['303', '3', '3rd floor'],
    ['1104', '11', '11th floor'],
    ['2201', '22', '22nd floor'],
    ['012', 'Ground', 'Ground floor'],
    ['A12', null, null],
    ['Garden Cottage', null, null],
    ['7', null, null],
]);

it('keeps a typed floor as the hotel wrote it', function (): void {
    expect(Room::floorLabel('Annex'))->toBe('Annex')->and(Room::floorLabel(''))->toBeNull();
});

it('fills the floor from the number on bulk create, or uses the typed one', function (): void {
    Livewire::actingAs($this->manager, 'web')->test(Form::class)
        ->set('room_category_id', $this->category->id)
        ->call('addRoomNumber', '101, 305, Garden')
        ->set('status', 'active')
        ->call('save')->assertHasNoErrors();

    expect(Room::query()->pluck('floor', 'room_number')->all())->toEqual(['101' => '1', '305' => '3', 'Garden' => null]);

    Livewire::actingAs($this->manager, 'web')->test(Form::class)
        ->set('room_category_id', $this->category->id)
        ->call('addRoomNumber', 'Villa 1')
        ->set('floor', 'Annex')
        ->set('status', 'active')
        ->call('save')->assertHasNoErrors();

    expect(Room::query()->where('room_number', 'Villa 1')->value('floor'))->toBe('Annex');
});

it('lets a manager correct the floor on edit', function (): void {
    $room = Room::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id, 'room_category_id' => $this->category->id,
        'room_number' => '101', 'floor' => '1', 'status' => 'active',
    ]);

    Livewire::actingAs($this->manager, 'web')->test(Form::class, ['room' => $room])
        ->assertSet('floor', '1')
        ->set('floor', 'Mezzanine')
        ->call('save')->assertHasNoErrors();

    expect($room->fresh()->floor)->toBe('Mezzanine');
});
