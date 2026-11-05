<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'check-branches']);
    $this->tenant->makeCurrent();
    $this->first = Property::factory()->for($this->tenant)->create();
    $this->second = Property::factory()->for($this->tenant)->create(['name' => 'Empty Annex']);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->first)->create();
});

afterEach(fn () => Tenant::forgetCurrent());

it('reports a room whose category sits in another branch, and changes nothing', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->second)->for($this->category, 'category')->create(['room_number' => 'M-1']);

    Artisan::call('branches:check');
    $output = Artisan::output();

    expect($output)->toContain('Rooms in a different branch from their category: 1')
        ->toContain('M-1')
        ->toContain('Nothing was changed.')
        ->and($room->fresh()->property_id)->toBe($this->second->id)
        ->and($this->category->fresh()->property_id)->toBe($this->first->id);
});

it('lists branches with no rooms as the place to look', function (): void {
    Room::factory()->for($this->tenant)->for($this->first)->for($this->category, 'category')->create();

    Artisan::call('branches:check');

    expect(Artisan::output())->toContain('Rooms in a different branch from their category: 0')
        ->toContain('Empty Annex');
});
