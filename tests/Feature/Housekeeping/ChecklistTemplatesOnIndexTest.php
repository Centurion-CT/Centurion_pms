<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Housekeeping\Index as HousekeepingIndex;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Checklist bundles UI on /dashboard/housekeeping. Bundles are
 * authored + edited here; the RoomCategories form attaches them.
 * These tests cover the inline authoring surface (create, item
 * add/remove/reorder, manager-only writes).
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'hk-templates']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager, 'activated_at' => now(),
    ]);
    $this->housekeeper = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::HouseCleaner, 'activated_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('exposes bundles + isManager to the view', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->assertViewHas('isManager', true)
        ->assertViewHas('bundles', fn ($rows) => $rows->isEmpty());
});

it('createBundle persists a new named bundle', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->set('newBundleName', 'Standard cleaning')
        ->call('createBundle');

    $bundle = HousekeepingChecklist::query()->first();
    expect($bundle)->not->toBeNull()
        ->and($bundle->name)->toBe('Standard cleaning')
        ->and((bool) $bundle->active)->toBeTrue();
});

it('addChecklistItem persists a new item onto the bundle', function (): void {
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'X', 'active' => true,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->set('newItemLabels', [$bundle->id => 'Bed made'])
        ->call('addChecklistItem', $bundle->id);

    expect(HousekeepingChecklistItem::query()->pluck('label')->all())->toBe(['Bed made']);
});

it('addChecklistItem silently ignores duplicates', function (): void {
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'X', 'active' => true,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->set('newItemLabels', [$bundle->id => 'Bed made'])
        ->call('addChecklistItem', $bundle->id)
        ->set('newItemLabels', [$bundle->id => 'bed made'])
        ->call('addChecklistItem', $bundle->id);

    expect(HousekeepingChecklistItem::query()->count())->toBe(1);
});

it('removeChecklistItem drops the row (existing inspections unaffected)', function (): void {
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'X', 'active' => true,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->set('newItemLabels', [$bundle->id => 'Towels x2'])
        ->call('addChecklistItem', $bundle->id);
    $item = HousekeepingChecklistItem::query()->first();

    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->call('removeChecklistItem', $item->id);

    expect(HousekeepingChecklistItem::query()->count())->toBe(0);
});

it('moveChecklistItem swaps sort_order with its neighbour', function (): void {
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'X', 'active' => true,
    ]);
    $c = Livewire::actingAs($this->manager, 'web')->test(HousekeepingIndex::class);
    foreach (['A', 'B', 'C'] as $label) {
        $c->set('newItemLabels', [$bundle->id => $label])
          ->call('addChecklistItem', $bundle->id);
    }

    $items = HousekeepingChecklistItem::query()->orderBy('sort_order')->get();
    // Move B up → order becomes B, A, C.
    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->call('moveChecklistItem', $items[1]->id, -1);

    $fresh = HousekeepingChecklistItem::query()->orderBy('sort_order')->pluck('label')->all();
    expect($fresh)->toBe(['B', 'A', 'C']);
});

it('non-managers can view bundles but their write calls are silent no-ops', function (): void {
    Livewire::actingAs($this->housekeeper, 'web')
        ->test(HousekeepingIndex::class)
        ->assertViewHas('isManager', false)
        ->set('newBundleName', 'Sneaky')
        ->call('createBundle');

    expect(HousekeepingChecklist::query()->count())->toBe(0);
});
