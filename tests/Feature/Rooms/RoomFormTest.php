<?php

declare(strict_types=1);

use App\Enums\RoomBookingType;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Rooms\Form;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'rooms-form-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders /dashboard/rooms/create for an onboarded operator', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://rooms-form-test.keverna.test/dashboard/rooms/create');

    $response->assertOk();
    $response->assertSeeLivewire(Form::class);
});

it('creates a new room with valid input + redirects to index', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class)
        ->set('name', 'Deluxe King Suite')
        ->set('type', 'Suite')
        ->set('description', 'Spacious river view')
        ->set('capacity_adults', 2)
        ->set('capacity_children', 1)
        ->set('base_rate', 45_000)
        ->set('min_nights', 1)
        ->set('booking_type', 'short_let')
        ->set('status', 'active')
        ->call('save')
        ->assertRedirect(route('tenant.rooms.index'));

    $room = Room::query()->where('name', 'Deluxe King Suite')->first();
    expect($room)->not->toBeNull();
    expect($room->tenant_id)->toBe($this->tenant->id);
    expect($room->property_id)->toBe($this->property->id);
    expect($room->booking_type)->toBe(RoomBookingType::ShortLet);
    expect($room->status)->toBe(RoomStatus::Active);
});

it('rejects capacity_adults = 0 (PRD §6.3.1 — min 1 adult)', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class)
        ->set('name', 'X')
        ->set('type', 'Suite')
        ->set('capacity_adults', 0)
        ->set('base_rate', 1000)
        ->set('booking_type', 'short_let')
        ->set('status', 'active')
        ->call('save')
        ->assertHasErrors(['capacity_adults']);
});

it('rejects capacity_adults > 50', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class)
        ->set('capacity_adults', 100)
        ->call('save')
        ->assertHasErrors(['capacity_adults']);
});

it('rejects negative base_rate', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class)
        ->set('base_rate', -1)
        ->call('save')
        ->assertHasErrors(['base_rate']);
});

it('rejects invalid booking_type', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class)
        ->set('booking_type', 'wormhole')
        ->call('save')
        ->assertHasErrors(['booking_type']);
});

it('rejects invalid status', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class)
        ->set('status', 'demolished')
        ->call('save')
        ->assertHasErrors(['status']);
});

it('requires a monthly_rate when booking_type is long_stay (PRD §6.3.2)', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class)
        ->set('name', 'Apartment 4B')
        ->set('type', 'Studio')
        ->set('capacity_adults', 2)
        ->set('base_rate', 25_000)
        ->set('booking_type', 'long_stay')
        ->set('monthly_rate', null)
        ->call('save')
        ->assertHasErrors(['monthly_rate']);
});

it('loads existing data in edit mode', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Existing Room',
        'type' => 'Studio',
        'capacity_adults' => 2,
        'base_rate' => 30_000,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class, ['room' => $room])
        ->assertSet('name', 'Existing Room')
        ->assertSet('type', 'Studio')
        ->assertSet('capacity_adults', 2)
        ->assertSet('base_rate', 30_000)
        ->assertSet('roomId', $room->id);
});

it('updates an existing room in edit mode', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Old Name',
        'base_rate' => 20_000,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Form::class, ['room' => $room])
        ->set('name', 'New Name')
        ->set('base_rate', 35_000)
        ->call('save')
        ->assertRedirect(route('tenant.rooms.index'));

    $room->refresh();
    expect($room->name)->toBe('New Name');
    expect($room->base_rate)->toBe(35_000);
});

it('404s when an operator tries to edit another tenant\'s room (isolation)', function (): void {
    // Foreign tenant + room.
    $otherTenant = Tenant::factory()->create(['slug' => 'rooms-form-other']);
    $otherProperty = Property::factory()->for($otherTenant)->create();
    Tenant::forgetCurrent();
    $otherTenant->makeCurrent();
    $foreignRoom = Room::factory()->for($otherTenant)->for($otherProperty)->create();
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    // Operator on $this->tenant attempts to edit the foreign room via direct URL.
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://rooms-form-test.keverna.test/dashboard/rooms/'.$foreignRoom->id.'/edit');

    $response->assertNotFound();
});
