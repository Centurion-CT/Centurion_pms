<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('auto-provisions a hidden category + room when a property is created in single_unit mode', function (): void {
    $property = Property::factory()->for($this->tenant)->create([
        'unit_mode' => 'single_unit',
        'name'      => 'Ikoyi Loft',
    ]);

    expect($property->isSingleUnit())->toBeTrue();

    $categoryCount = RoomCategory::query()->where('property_id', $property->id)->count();
    $roomCount = Room::query()->where('property_id', $property->id)->count();

    expect($categoryCount)->toBe(1);
    expect($roomCount)->toBe(1);
});

it('does not auto-provision anything for multi_room properties', function (): void {
    $property = Property::factory()->for($this->tenant)->create([
        'unit_mode' => 'multi_room',
    ]);

    expect($property->isSingleUnit())->toBeFalse();

    // Nothing auto-created — operator does it themselves.
    expect(RoomCategory::query()->where('property_id', $property->id)->count())->toBe(0);
    expect(Room::query()->where('property_id', $property->id)->count())->toBe(0);
});

it('routes the onboarding step through the auto-provision path when single_unit is picked', function (): void {
    // Onboarding writes to properties via updateOrCreate on tenant_id.
    // The observer must fire on the create path the same way.
    $property = \App\Models\Property::query()->updateOrCreate(
        ['tenant_id' => $this->tenant->id],
        [
            'name' => 'Ikoyi Loft',
            'type' => \App\Enums\PropertyType::ServicedApartment,
            'address' => '5 Bourdillon Rd',
            'unit_mode' => 'single_unit',
            'currency_code' => 'NGN',
        ],
    );

    expect($property->isSingleUnit())->toBeTrue();
    expect(\App\Models\RoomCategory::query()->where('property_id', $property->id)->count())->toBe(1);
    expect(\App\Models\Room::query()->where('property_id', $property->id)->count())->toBe(1);
});

it('is idempotent: re-saving a single_unit property does not create duplicates', function (): void {
    $property = Property::factory()->for($this->tenant)->create([
        'unit_mode' => 'single_unit',
    ]);
    $property->name = 'Renamed';
    $property->save();

    expect(RoomCategory::query()->where('property_id', $property->id)->count())->toBe(1);
    expect(Room::query()->where('property_id', $property->id)->count())->toBe(1);
});
