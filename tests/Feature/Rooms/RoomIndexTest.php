<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Rooms\Index;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'rooms-index-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders the rooms index for an onboarded operator', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://rooms-index-test.keverna.test/dashboard/rooms');

    $response->assertOk();
    $response->assertSeeLivewire(Index::class);
});

it('shows an empty state when no rooms exist', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->assertSeeHtml('data-testid="empty"');
});

it('lists every room belonging to the current tenant', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->count(3)->create();

    $component = Livewire::actingAs($this->operator, 'web')->test(Index::class);

    foreach (Room::all() as $room) {
        $component->assertSeeHtml('data-testid="room-row-'.$room->id.'"');
    }
});

it('filters by status', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->count(2)->create();
    Room::factory()->for($this->tenant)->for($this->property)->inactive()->create();

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->set('statusFilter', RoomStatus::Inactive->value)
        ->assertViewHas('rooms', fn ($p) => $p->total() === 1);
});

it('filters by search (name or type)', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)
        ->create(['name' => 'Deluxe King', 'type' => 'Suite']);
    Room::factory()->for($this->tenant)->for($this->property)
        ->create(['name' => 'Cozy Studio', 'type' => 'Studio']);

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->set('search', 'Studio')
        ->assertViewHas('rooms', fn ($p) => $p->total() === 1);
});

it('paginates beyond 15 rooms', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->count(20)->create();

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->assertViewHas('rooms', fn ($p) => $p->total() === 20 && $p->count() === 15);
});

it('soft-deletes a room via the delete action', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->call('delete', $room->id);

    expect(Room::find($room->id))->toBeNull();
    expect(Room::withTrashed()->find($room->id))->not->toBeNull();
});

it('does NOT show rooms from other tenants in the listing (cross-tenant isolation)', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->create(['name' => 'Mine']);

    $otherTenant = Tenant::factory()->create();
    $otherProperty = Property::factory()->for($otherTenant)->create();
    Room::factory()->for($otherTenant)->for($otherProperty)->create(['name' => 'Theirs']);

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->assertViewHas('rooms', fn ($p) => $p->total() === 1 && $p->first()->name === 'Mine');
});

it('is gated by the onboarding wizard', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'no-onboard']);
    $unfinished = Operator::factory()->for($tenant)->unverified()->create();

    Tenant::forgetCurrent();
    $tenant->makeCurrent();

    $response = $this->actingAs($unfinished, 'web')
        ->get('http://no-onboard.keverna.test/dashboard/rooms');

    $response->assertRedirect(route('tenant.onboarding.property'));
});
