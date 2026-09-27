<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Rooms\Form;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Bulk-create UX: the room-number field on the create form accepts
 * multiple values as badges (comma / Enter tokenises, click × to
 * remove). On submit we create one Room per badge — one round trip,
 * one form.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'rooms-bulk']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()
        ->for($this->tenant)->for($this->property)->create([
            'capacity_adults' => 2, 'capacity_children' => 0,
            'base_rate' => 30_000, 'min_nights' => 1,
        ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('creates one room per badge in a single submit', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->set('room_category_id', $this->category->id)
        ->call('addRoomNumber', '101')
        ->call('addRoomNumber', '102')
        ->call('addRoomNumber', '103')
        ->set('status', 'active')
        ->call('save')
        ->assertRedirect(route('tenant.rooms.index'));

    expect(Room::query()->count())->toBe(3);
    expect(Room::query()->pluck('room_number')->sort()->values()->all())
        ->toBe(['101', '102', '103']);
});

it('splits a comma-separated paste in one addRoomNumber call', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->call('addRoomNumber', '201, 202, 203 , 204');

    expect(Room::query()->count())->toBe(0); // not saved yet
    // Badges seeded on the component:
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->call('addRoomNumber', '201, 202, 203 , 204')
        ->assertSet('room_numbers', ['201', '202', '203', '204']);
});

it('drops duplicate + blank tokens silently', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->call('addRoomNumber', '101,,101,102, ,101')
        ->assertSet('room_numbers', ['101', '102']);
});

it('flushes a residual buffer on submit', function (): void {
    // Operator typed the last one but forgot to press Enter — we still
    // treat it as a badge instead of losing the value.
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->set('room_category_id', $this->category->id)
        ->call('addRoomNumber', '301')
        ->set('room_number', '302')     // still in the buffer
        ->set('status', 'active')
        ->call('save')
        ->assertRedirect(route('tenant.rooms.index'));

    expect(Room::query()->pluck('room_number')->sort()->values()->all())
        ->toBe(['301', '302']);
});

it('removes a badge by index', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->call('addRoomNumber', '401, 402, 403')
        ->call('removeRoomNumber', 1)   // drop 402
        ->assertSet('room_numbers', ['401', '403']);
});

it('rejects a batch where one number already exists in the property', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'room_number' => '501',
        'status' => RoomStatus::Active->value,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->set('room_category_id', $this->category->id)
        ->call('addRoomNumber', '501')     // collision
        ->call('addRoomNumber', '502')
        ->set('status', 'active')
        ->call('save')
        ->assertHasErrors('room_numbers.0');

    // Nothing new should have landed — validation must be atomic.
    expect(Room::query()->count())->toBe(1);
});

it('rejects a batch with a duplicate token inside itself', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->set('room_category_id', $this->category->id)
        ->call('addRoomNumber', '601, 602, 601') // add dedups, so seed via raw set
        ->set('room_numbers', ['601', '602', '601'])
        ->set('status', 'active')
        ->call('save')
        ->assertHasErrors('room_numbers.2');

    expect(Room::query()->count())->toBe(0);
});
