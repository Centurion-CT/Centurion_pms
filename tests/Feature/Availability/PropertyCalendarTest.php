<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Livewire\Dashboard\Calendar\PropertyView;
use App\Models\AvailabilityBlock;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'calendar-prop']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders the property calendar at /dashboard/calendar for an onboarded operator', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://calendar-prop.keverna.test/dashboard/calendar');

    $response->assertOk();
    $response->assertSeeLivewire(PropertyView::class);
});

it('shows the empty-state when no Active rooms exist', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyView::class)
        ->assertSeeHtml('data-testid="no-rooms"');
});

it('lists active rooms but hides inactive ones', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->create(['name' => 'Active Room']);
    Room::factory()->for($this->tenant)->for($this->property)->inactive()->create(['name' => 'Hidden Room']);

    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyView::class)
        ->assertSee('Active Room')
        ->assertDontSee('Hidden Room');
});

it('marks booked + blocked + free cells correctly', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    AvailabilityBlock::factory()->for($this->tenant)->for($room)->booked()
        ->create(['date' => '2026-07-10']);
    AvailabilityBlock::factory()->for($this->tenant)->for($room)
        ->create(['date' => '2026-07-15', 'type' => AvailabilityBlockType::Blocked]);

    // The view-data matrix is the authoritative source of cell state — assert
    // against it to avoid flakiness on whitespace in rendered HTML attributes.
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyView::class, ['month' => '2026-07'])
        ->assertViewHas('matrix', fn ($matrix) => $matrix[$room->id][10] === 'booked'
            && $matrix[$room->id][15] === 'blocked'
            && $matrix[$room->id][20] === null);
});

it('does NOT show blocks from another tenant\'s room with the same id', function (): void {
    $myRoom = Room::factory()->for($this->tenant)->for($this->property)->create();
    AvailabilityBlock::factory()->for($this->tenant)->for($myRoom)
        ->create(['date' => '2026-07-12', 'type' => AvailabilityBlockType::Blocked]);

    // Seed a colliding room+block on another tenant.
    $other = Tenant::factory()->create();
    Tenant::forgetCurrent();
    $other->makeCurrent();
    $otherProp = Property::factory()->for($other)->create();
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create();
    AvailabilityBlock::factory()->for($other)->for($otherRoom)->booked()
        ->create(['date' => '2026-07-13']);

    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyView::class, ['month' => '2026-07'])
        ->assertSeeHtml('data-state="blocked"')
        ->assertDontSeeHtml('data-state="booked"');
});

it('previousMonth and nextMonth navigate by exactly one month', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyView::class, ['month' => '2026-06'])
        ->call('nextMonth')
        ->assertSet('month', '2026-07')
        ->call('previousMonth')
        ->call('previousMonth')
        ->assertSet('month', '2026-05');
});
